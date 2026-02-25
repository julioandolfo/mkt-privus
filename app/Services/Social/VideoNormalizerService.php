<?php

namespace App\Services\Social;

use App\Models\SystemLog;

/**
 * Normaliza vídeos para publicação no Instagram Reels.
 *
 * Requisitos Instagram Reels:
 * - Container: MP4
 * - Codec vídeo: H.264 (AVC)
 * - Codec áudio: AAC
 * - Resolução: exatamente 1080×1920 (9:16)
 * - FPS: 30
 * - Duração: 3–180 segundos
 *
 * Usa ffmpeg para re-encodar quando necessário.
 */
class VideoNormalizerService
{
    private const TARGET_WIDTH  = 1080;
    private const TARGET_HEIGHT = 1920;
    private const TARGET_FPS    = 30;

    /**
     * Verifica se o ffmpeg/ffprobe estão disponíveis.
     */
    public function isAvailable(): bool
    {
        $check = @shell_exec('ffmpeg -version 2>&1');
        return $check && str_contains($check, 'ffmpeg version');
    }

    /**
     * Analisa specs do vídeo e retorna informações relevantes.
     */
    public function analyze(string $filePath): ?array
    {
        if (!file_exists($filePath)) {
            return null;
        }

        $cmd    = sprintf('ffprobe -v quiet -print_format json -show_streams -show_format %s 2>&1', escapeshellarg($filePath));
        $output = shell_exec($cmd);

        if (!$output) {
            return null;
        }

        $info    = json_decode($output, true);
        $streams = $info['streams'] ?? [];
        $format  = $info['format'] ?? [];

        $videoStream = collect($streams)->firstWhere('codec_type', 'video');
        $audioStream = collect($streams)->firstWhere('codec_type', 'audio');

        if (!$videoStream) {
            return null;
        }

        return [
            'video_codec' => strtolower($videoStream['codec_name'] ?? 'unknown'),
            'audio_codec' => strtolower($audioStream['codec_name'] ?? 'none'),
            'width'       => (int) ($videoStream['width'] ?? 0),
            'height'      => (int) ($videoStream['height'] ?? 0),
            'duration'    => (float) ($format['duration'] ?? $videoStream['duration'] ?? 0),
            'fps'         => $this->parseFps($videoStream['r_frame_rate'] ?? ''),
            'bitrate'     => (int) ($format['bit_rate'] ?? 0),
            'file_size'   => (int) ($format['size'] ?? filesize($filePath)),
            'pix_fmt'     => $videoStream['pix_fmt'] ?? '',
            'profile'     => $videoStream['profile'] ?? '',
        ];
    }

    /**
     * Verifica se o vídeo precisa ser normalizado para Instagram Reels.
     */
    public function needsNormalization(array $specs): bool
    {
        // Codec errado
        if (!in_array($specs['video_codec'], ['h264', 'avc'])) {
            return true;
        }

        if ($specs['audio_codec'] !== 'none' && $specs['audio_codec'] !== 'aac') {
            return true;
        }

        // Resolução não é exatamente 1080×1920
        if ($specs['width'] !== self::TARGET_WIDTH || $specs['height'] !== self::TARGET_HEIGHT) {
            return true;
        }

        // Pixel format não é yuv420p (compatibilidade máxima)
        if ($specs['pix_fmt'] && $specs['pix_fmt'] !== 'yuv420p') {
            return true;
        }

        return false;
    }

    /**
     * Normaliza o vídeo para Instagram Reels (1080×1920, H.264+AAC).
     * Usa scale+pad para manter proporção original e preencher com preto.
     *
     * @return string|null  Caminho do arquivo normalizado, ou null se falhou
     */
    public function normalize(string $inputPath, ?string $outputPath = null): ?string
    {
        if (!$this->isAvailable()) {
            SystemLog::warning('social', 'video.ffmpeg_missing', 'ffmpeg não disponível para normalizar vídeo');
            return null;
        }

        if (!file_exists($inputPath)) {
            return null;
        }

        $specs = $this->analyze($inputPath);
        if (!$specs) {
            return null;
        }

        if (!$this->needsNormalization($specs)) {
            return $inputPath; // Já está no formato correto
        }

        if (!$outputPath) {
            $pathInfo   = pathinfo($inputPath);
            $outputPath = $pathInfo['dirname'] . '/' . $pathInfo['filename'] . '_reels.mp4';
        }

        // Construir filtro de vídeo:
        // 1. Scale para caber em 1080×1920 mantendo proporção
        // 2. Pad para preencher exatamente 1080×1920 com preto
        $vf = sprintf(
            "scale=%d:%d:force_original_aspect_ratio=decrease,pad=%d:%d:(ow-iw)/2:(oh-ih)/2:black,setsar=1",
            self::TARGET_WIDTH,
            self::TARGET_HEIGHT,
            self::TARGET_WIDTH,
            self::TARGET_HEIGHT
        );

        $cmd = sprintf(
            'ffmpeg -y -i %s -vf "%s" -r %d -c:v libx264 -preset medium -crf 23 -profile:v high -pix_fmt yuv420p -c:a aac -b:a 192k -ar 44100 -ac 2 -movflags +faststart -t 180 %s 2>&1',
            escapeshellarg($inputPath),
            $vf,
            self::TARGET_FPS,
            escapeshellarg($outputPath)
        );

        SystemLog::info('social', 'video.normalize.start', "Normalizando vídeo para Reels: {$specs['width']}x{$specs['height']} → " . self::TARGET_WIDTH . 'x' . self::TARGET_HEIGHT, [
            'input'       => basename($inputPath),
            'video_codec' => $specs['video_codec'],
            'audio_codec' => $specs['audio_codec'],
            'resolution'  => "{$specs['width']}x{$specs['height']}",
            'duration'    => $specs['duration'],
        ]);

        $output   = shell_exec($cmd);
        $exitCode = -1;

        // Verificar se arquivo de saída existe e é válido
        if (file_exists($outputPath) && filesize($outputPath) > 0) {
            $newSpecs = $this->analyze($outputPath);

            if ($newSpecs && $newSpecs['width'] === self::TARGET_WIDTH && $newSpecs['height'] === self::TARGET_HEIGHT) {
                SystemLog::info('social', 'video.normalize.success', 'Vídeo normalizado com sucesso', [
                    'output'      => basename($outputPath),
                    'resolution'  => "{$newSpecs['width']}x{$newSpecs['height']}",
                    'duration'    => $newSpecs['duration'],
                    'file_size'   => $newSpecs['file_size'],
                ]);
                return $outputPath;
            }
        }

        SystemLog::error('social', 'video.normalize.failed', 'Falha ao normalizar vídeo', [
            'input'  => basename($inputPath),
            'output' => $output ? substr($output, -500) : 'no output',
        ]);

        // Limpar arquivo parcial se existir
        if (file_exists($outputPath)) {
            @unlink($outputPath);
        }

        return null;
    }

    private function parseFps(string $fpsStr): float
    {
        if (str_contains($fpsStr, '/')) {
            $parts = explode('/', $fpsStr);
            $num   = (int) ($parts[0] ?? 0);
            $den   = (int) ($parts[1] ?? 1);
            return $den > 0 ? round($num / $den, 2) : 0;
        }

        return (float) $fpsStr;
    }
}
