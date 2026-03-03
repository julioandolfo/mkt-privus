<?php

namespace App\Services\Email;

use App\Models\SystemLog;
use Illuminate\Support\Facades\Storage;

/**
 * Serviço para converter imagens SVG para PNG
 * Necessário porque SVG não é suportado pela maioria dos clientes de email
 */
class SvgConverterService
{
    /**
     * Converte um arquivo SVG para PNG
     *
     * @param string $svgPath Caminho do arquivo SVG no storage
     * @param int $width Largura desejada (opcional)
     * @param int $height Altura desejada (opcional)
     * @return string|null Caminho do arquivo PNG gerado ou null se falhar
     */
    public function convertToPng(string $svgPath, ?int $width = null, ?int $height = null): ?string
    {
        try {
            SystemLog::info('email', 'svg.convert.start', "Iniciando conversão SVG para PNG", [
                'svg_path' => $svgPath,
                'width' => $width,
                'height' => $height,
            ]);

            // Verificar se o arquivo existe
            if (!Storage::disk('public')->exists($svgPath)) {
                SystemLog::error('email', 'svg.convert.file_not_found', "Arquivo SVG não encontrado", [
                    'svg_path' => $svgPath,
                ]);
                return null;
            }

            // rsvg-convert é o mais confiável para SVGs complexos (CorelDRAW, Illustrator, etc.)
            if ($this->hasRsvgConvert()) {
                $result = $this->convertUsingRsvg($svgPath, $width, $height);
                if ($result) return $result;
            }

            if ($this->hasImagick()) {
                $result = $this->convertUsingImagick($svgPath, $width, $height);
                if ($result) return $result;
            }

            if ($this->hasImageMagickCli()) {
                $result = $this->convertUsingCli($svgPath, $width, $height);
                if ($result) return $result;
            }

            if ($this->hasInkscape()) {
                return $this->convertUsingInkscape($svgPath, $width, $height);
            }

            // Se nenhum método disponível, loga e retorna null
            SystemLog::warning('email', 'svg.convert.no_converter', "Nenhum conversor SVG disponível. Instale librsvg (rsvg-convert), Imagick PHP extension, ImageMagick CLI ou Inkscape.", [
                'svg_path' => $svgPath,
            ]);

            return null;

        } catch (\Throwable $e) {
            SystemLog::error('email', 'svg.convert.error', "Erro ao converter SVG: {$e->getMessage()}", [
                'svg_path' => $svgPath,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return null;
        }
    }

    /**
     * Verifica se rsvg-convert está disponível (librsvg - método mais confiável para SVG)
     */
    private function hasRsvgConvert(): bool
    {
        $output = shell_exec('which rsvg-convert 2>/dev/null');
        return !empty(trim($output ?? ''));
    }

    /**
     * Remove DOCTYPE e namespaces problemáticos do SVG antes de converter
     * SVGs do CorelDRAW/Illustrator têm DOCTYPE que causa timeout ao converter
     */
    private function sanitizeSvgForConversion(string $svgContent): string
    {
        // Remove DOCTYPE (causa tentativa de buscar DTD externo e timeout)
        $svgContent = preg_replace('/<!DOCTYPE[^>]*>/i', '', $svgContent);

        // Remove namespace CorelDRAW (não reconhecido por conversores)
        $svgContent = str_replace(' xmlns:xodm="http://www.corel.com/coreldraw/odm/2003"', '', $svgContent);

        // Remove processing instructions problemáticas
        $svgContent = preg_replace('/<\?[^>]*\?>\s*/', '', $svgContent);

        return trim($svgContent);
    }

    /**
     * Converte usando rsvg-convert (librsvg) - melhor suporte a SVG complexos
     */
    private function convertUsingRsvg(string $svgPath, ?int $width, ?int $height): ?string
    {
        try {
            $fullPath    = Storage::disk('public')->path($svgPath);
            $pngPath     = preg_replace('/\.svg$/i', '.png', $svgPath);
            $pngFullPath = Storage::disk('public')->path($pngPath);

            $directory = dirname($pngFullPath);
            if (!is_dir($directory)) {
                mkdir($directory, 0755, true);
            }

            // Cria SVG sanitizado temporário (sem DOCTYPE que causa timeout)
            $svgContent  = file_get_contents($fullPath);
            $cleanContent = $this->sanitizeSvgForConversion($svgContent);
            $tmpSvg = $fullPath . '.tmp.svg';
            file_put_contents($tmpSvg, $cleanContent);

            $sizeOption = '';
            if ($width && $height) {
                $sizeOption = " -w {$width} -h {$height}";
            } elseif ($width) {
                $sizeOption = " -w {$width}";
            } elseif ($height) {
                $sizeOption = " -h {$height}";
            }

            // Tenta converter o SVG sanitizado
            $cmd    = "rsvg-convert{$sizeOption} -o \"{$pngFullPath}\" \"{$tmpSvg}\" 2>&1";
            $output = shell_exec($cmd);

            // Remove o temporário
            if (file_exists($tmpSvg)) {
                unlink($tmpSvg);
            }

            if (!file_exists($pngFullPath) || filesize($pngFullPath) < 100) {
                SystemLog::error('email', 'svg.convert.rsvg_failed', "rsvg-convert falhou", [
                    'svg_path' => $svgPath,
                    'command'  => $cmd,
                    'output'   => $output,
                    'png_exists' => file_exists($pngFullPath),
                    'png_size'   => file_exists($pngFullPath) ? filesize($pngFullPath) : 0,
                ]);
                return null;
            }

            SystemLog::info('email', 'svg.convert.rsvg_success', "SVG convertido com sucesso via rsvg-convert", [
                'svg_path' => $svgPath,
                'png_path' => $pngPath,
                'png_size' => filesize($pngFullPath),
            ]);

            return $pngPath;

        } catch (\Throwable $e) {
            SystemLog::error('email', 'svg.convert.rsvg_error', "Erro rsvg-convert: {$e->getMessage()}", [
                'svg_path' => $svgPath,
                'error'    => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Verifica se a extensão PHP Imagick está instalada com suporte a SVG
     */
    private function hasImagick(): bool
    {
        if (!extension_loaded('imagick')) {
            return false;
        }

        try {
            $imagick = new \Imagick();
            $formats = $imagick->queryFormats();
            return in_array('SVG', $formats) || in_array('MSVG', $formats);
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Converte usando PHP Imagick
     */
    private function convertUsingImagick(string $svgPath, ?int $width, ?int $height): ?string
    {
        try {
            $fullPath = Storage::disk('public')->path($svgPath);
            $svgContent = Storage::disk('public')->get($svgPath);

            $imagick = new \Imagick();

            // Configurar para renderizar SVG corretamente
            $imagick->setBackgroundColor(new \ImagickPixel('transparent'));
            $imagick->readImageBlob($svgContent);

            // Redimensionar se necessário
            if ($width || $height) {
                $imagick->resizeImage(
                    $width ?? $imagick->getImageWidth(),
                    $height ?? $imagick->getImageHeight(),
                    \Imagick::FILTER_LANCZOS,
                    1
                );
            }

            $imagick->setImageFormat('png32');

            // Gerar nome do arquivo PNG
            $pngPath = str_replace('.svg', '.png', $svgPath);
            $pngFullPath = Storage::disk('public')->path($pngPath);

            // Garantir que o diretório existe
            $directory = dirname($pngFullPath);
            if (!is_dir($directory)) {
                mkdir($directory, 0755, true);
            }

            $imagick->writeImage($pngFullPath);
            $imagick->clear();
            $imagick->destroy();

            SystemLog::info('email', 'svg.convert.imagick_success', "SVG convertido para PNG usando Imagick", [
                'svg_path' => $svgPath,
                'png_path' => $pngPath,
            ]);

            return $pngPath;

        } catch (\Throwable $e) {
            SystemLog::error('email', 'svg.convert.imagick_failed', "Imagick falhou: {$e->getMessage()}", [
                'svg_path' => $svgPath,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Verifica se ImageMagick CLI está disponível
     */
    private function hasImageMagickCli(): bool
    {
        // No Windows, verificar se magick.exe ou convert.exe existe
        // No Linux, verificar comandos 'convert' ou 'magick'
        $isWindows = strtoupper(substr(PHP_OS, 0, 3)) === 'WIN';

        if ($isWindows) {
            // Verificar se é o ImageMagick convert ou Windows convert
            $output = shell_exec('where magick 2>&1');
            if ($output && !str_contains($output, 'not found')) {
                return true;
            }
        } else {
            $output = shell_exec('which convert 2>&1');
            if ($output && !str_contains($output, 'not found')) {
                // Verificar se é ImageMagick convert
                $version = shell_exec('convert --version 2>&1');
                if ($version && str_contains($version, 'ImageMagick')) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Converte usando ImageMagick CLI
     */
    private function convertUsingCli(string $svgPath, ?int $width, ?int $height): ?string
    {
        try {
            $fullPath = Storage::disk('public')->path($svgPath);
            $pngPath = str_replace('.svg', '.png', $svgPath);
            $pngFullPath = Storage::disk('public')->path($pngPath);

            // Garantir que o diretório existe
            $directory = dirname($pngFullPath);
            if (!is_dir($directory)) {
                mkdir($directory, 0755, true);
            }

            // Construir comando
            $isWindows = strtoupper(substr(PHP_OS, 0, 3)) === 'WIN';
            $command = $isWindows ? 'magick' : 'convert';

            $resizeOption = '';
            if ($width && $height) {
                $resizeOption = " -resize {$width}x{$height} ";
            } elseif ($width) {
                $resizeOption = " -resize {$width}x ";
            } elseif ($height) {
                $resizeOption = " -resize x{$height} ";
            }

            $cmd = "{$command} \"{$fullPath}\" {$resizeOption} \"{$pngFullPath}\" 2>&1";

            $output = shell_exec($cmd);
            $returnCode = 0;

            if (!file_exists($pngFullPath) || filesize($pngFullPath) < 100) {
                SystemLog::error('email', 'svg.convert.cli_failed', "ImageMagick CLI falhou", [
                    'svg_path' => $svgPath,
                    'command' => $cmd,
                    'output' => $output,
                ]);
                return null;
            }

            SystemLog::info('email', 'svg.convert.cli_success', "SVG convertido para PNG usando CLI", [
                'svg_path' => $svgPath,
                'png_path' => $pngPath,
            ]);

            return $pngPath;

        } catch (\Throwable $e) {
            SystemLog::error('email', 'svg.convert.cli_error', "Erro CLI: {$e->getMessage()}", [
                'svg_path' => $svgPath,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Verifica se Inkscape está instalado
     */
    private function hasInkscape(): bool
    {
        $isWindows = strtoupper(substr(PHP_OS, 0, 3)) === 'WIN';

        if ($isWindows) {
            $output = shell_exec('where inkscape 2>&1');
            return $output && !str_contains($output, 'not found');
        } else {
            $output = shell_exec('which inkscape 2>&1');
            return $output && !str_contains($output, 'not found');
        }
    }

    /**
     * Converte usando Inkscape
     */
    private function convertUsingInkscape(string $svgPath, ?int $width, ?int $height): ?string
    {
        try {
            $fullPath = Storage::disk('public')->path($svgPath);
            $pngPath = str_replace('.svg', '.png', $svgPath);
            $pngFullPath = Storage::disk('public')->path($pngPath);

            // Garantir que o diretório existe
            $directory = dirname($pngFullPath);
            if (!is_dir($directory)) {
                mkdir($directory, 0755, true);
            }

            // Construir comando Inkscape
            $exportOption = "--export-filename=\"{$pngFullPath}\" --export-type=png";

            if ($width && $height) {
                $exportOption .= " -w {$width} -h {$height}";
            }

            $cmd = "inkscape \"{$fullPath}\" {$exportOption} 2>&1";

            $output = shell_exec($cmd);

            if (!file_exists($pngFullPath) || filesize($pngFullPath) < 100) {
                SystemLog::error('email', 'svg.convert.inkscape_failed', "Inkscape falhou", [
                    'svg_path' => $svgPath,
                    'command' => $cmd,
                    'output' => $output,
                ]);
                return null;
            }

            SystemLog::info('email', 'svg.convert.inkscape_success', "SVG convertido para PNG usando Inkscape", [
                'svg_path' => $svgPath,
                'png_path' => $pngPath,
            ]);

            return $pngPath;

        } catch (\Throwable $e) {
            SystemLog::error('email', 'svg.convert.inkscape_error', "Erro Inkscape: {$e->getMessage()}", [
                'svg_path' => $svgPath,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Verifica se a conversão está disponível
     */
    public function isConversionAvailable(): bool
    {
        return $this->hasRsvgConvert() || $this->hasImagick() || $this->hasImageMagickCli() || $this->hasInkscape();
    }

    /**
     * Retorna qual método de conversão está disponível
     */
    public function getAvailableMethod(): ?string
    {
        if ($this->hasRsvgConvert()) {
            return 'rsvg-convert';
        }
        if ($this->hasImagick()) {
            return 'imagick';
        }
        if ($this->hasImageMagickCli()) {
            return 'imagemagick-cli';
        }
        if ($this->hasInkscape()) {
            return 'inkscape';
        }
        return null;
    }
}
