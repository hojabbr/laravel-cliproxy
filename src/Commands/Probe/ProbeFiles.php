<?php

namespace Hojabbr\CliProxy\Commands\Probe;

/**
 * The probe's inputs, built in memory so the check needs no extension (GD, Imagick) and no fixture
 * files in the package.
 *
 * @internal
 */
final class ProbeFiles
{
    public const string PDF_WORD = 'PELICAN';

    /**
     * A 32x32 solid red PNG.
     */
    public static function redSquare(): string
    {
        $size = 32;
        $row = "\x00".str_repeat("\xFF\x00\x00", $size);
        $chunk = static fn (string $type, string $data): string => pack('N', strlen($data)).$type.$data.pack('N', crc32($type.$data));

        return "\x89PNG\r\n\x1A\n"
            .$chunk('IHDR', pack('NNCCCCC', $size, $size, 8, 2, 0, 0, 0))
            .$chunk('IDAT', (string) gzcompress(str_repeat($row, $size)))
            .$chunk('IEND', '');
    }

    /**
     * A one-page PDF printing one word, with a correct cross-reference table.
     */
    public static function pdf(string $word): string
    {
        $stream = "BT /F1 36 Tf 40 90 Td ({$word}) Tj ET";
        $objects = [
            '<< /Type /Catalog /Pages 2 0 R >>',
            '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 400 200] /Contents 4 0 R /Resources << /Font << /F1 5 0 R >> >> >>',
            '<< /Length '.strlen($stream)." >>\nstream\n{$stream}\nendstream",
            '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
        ];

        $pdf = "%PDF-1.4\n";
        $offsets = [];

        foreach ($objects as $index => $object) {
            $offsets[] = strlen($pdf);
            $pdf .= ($index + 1)." 0 obj\n{$object}\nendobj\n";
        }

        $xref = strlen($pdf);
        $pdf .= "xref\n0 ".(count($objects) + 1)."\n0000000000 65535 f \n";

        foreach ($offsets as $offset) {
            $pdf .= sprintf("%010d 00000 n \n", $offset);
        }

        return $pdf.'trailer << /Size '.(count($objects) + 1)." /Root 1 0 R >>\nstartxref\n{$xref}\n%%EOF\n";
    }

    /**
     * About 5,000 tokens of fixed text: above every current model's minimum cacheable prefix.
     */
    public static function longPrefix(): string
    {
        $lines = [];

        for ($line = 1; $line <= 400; $line++) {
            $lines[] = "Reference line {$line}: the quick brown fox jumps over the lazy dog near river bend number {$line}.";
        }

        return "You are a probe. Ignore the reference text below.\n\n".implode("\n", $lines);
    }
}
