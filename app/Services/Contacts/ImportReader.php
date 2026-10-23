<?php

namespace App\Services\Contacts;

use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Reader\Xls;
use PhpOffice\PhpSpreadsheet\RichText\RichText;
use ZipArchive;

class ImportReader
{
    public function read(UploadedFile $file): array
    {
        $ext = strtolower($file->getClientOriginalExtension());
        if (! in_array($ext, ['csv', 'xls', 'xlsx'], true)) {
            $this->fail('Upload a CSV, XLS or XLSX file.');
        }
        $rows = match ($ext) {
            'csv' => $this->csv($file->getRealPath()), 'xls' => $this->xls($file->getRealPath()), 'xlsx' => $this->xlsx($file->getRealPath())
        };
        if (count($rows) < 2) {
            $this->fail('Include a header row and at least one contact.');
        }
        $headers = array_shift($rows);
        $headers = array_map(fn ($v) => mb_substr(trim((string) $v), 0, 150), $headers);
        $headers[0] = preg_replace('/^\x{FEFF}/u', '', $headers[0]);
        if (count($headers) > 50 || ! array_filter($headers)) {
            $this->fail('Use at most 50 columns and a nonempty header row.');
        }

        return [$headers, $rows];
    }

    private function csv(string $path): array
    {
        $f = fopen($path, 'rb');
        $rows = [];
        try {
            while (($row = fgetcsv($f, 1000000, ',', '"', '')) !== false) {
                if ($row === [null]) {
                    continue;
                }$this->check($row, count($rows));
                $rows[] = array_map(fn ($v) => (string) $v, $row);
            }
        } finally {
            fclose($f);
        }

        return $rows;
    }

    private function xls(string $path): array
    {
        $book = null;
        try {
            $reader = new Xls;
            if (! $reader->canRead($path)) {
                $this->fail('Invalid XLS workbook.');
            }
            $info = $reader->listWorksheetInfo($path);
            if (! $info || count($info) > 1000 || $info[0]['totalRows'] > 10001 || $info[0]['totalColumns'] > 50) {
                $this->fail('Use at most 10,000 contact rows and 50 columns.');
            }
            $reader->setReadDataOnly(true);
            $reader->setReadEmptyCells(false);
            $reader->setLoadSheetsOnly([$info[0]['worksheetName']]);
            $book = $reader->load($path);
            $sheet = $book->getSheet(0);
            $rows = [];
            for ($r = 1; $r <= $info[0]['totalRows']; $r++) {
                $values = [];
                for ($c = 1; $c <= $info[0]['totalColumns']; $c++) {
                    $cell = $sheet->getCell(Coordinate::stringFromColumnIndex($c).$r);
                    if ($cell->getDataType() === DataType::TYPE_FORMULA) {
                        $this->fail('Formula cells are not supported; paste their values before uploading.');
                    }
                    $value = $cell->getValue();
                    if ($value instanceof RichText) {
                        $value = $value->getPlainText();
                    }
                    $values[] = (string) ($value ?? '');
                }
                if (! array_filter($values, fn ($v) => $v !== '')) {
                    continue;
                }
                $this->check($values, count($rows));
                $rows[] = $values;
            }

            return $rows;
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            $this->fail('Unable to read XLS workbook. Use an unencrypted Excel workbook with text phone numbers.');
        } finally {
            $book?->disconnectWorksheets();
        }
    }

    private function xml(string $text): \SimpleXMLElement
    {
        if (stripos($text, '<!DOCTYPE') !== false || stripos($text, '<!ENTITY') !== false) {
            $this->fail('Unsafe workbook XML.');
        }
        $old = libxml_use_internal_errors(true);
        try {
            $xml = simplexml_load_string($text, \SimpleXMLElement::class, LIBXML_NONET);
            if (! $xml) {
                $this->fail('Invalid workbook XML.');
            }

            return $xml;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($old);
        }
    }

    private function xlsx(string $path): array
    {
        $zip = new ZipArchive;
        if ($zip->open($path) !== true) {
            $this->fail('Invalid XLSX workbook.');
        }
        try {
            $size = 0;
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $size += ($zip->statIndex($i)['size'] ?? 0);
                if ($size > 30 * 1024 * 1024 || $zip->numFiles > 1000) {
                    $this->fail('Workbook exceeds the expanded size limit.');
                }
            }
            $strings = [];
            $shared = $zip->getFromName('xl/sharedStrings.xml');
            if ($shared !== false) {
                $xml = $this->xml($shared);
                foreach ($xml->xpath('//*[local-name()="si"]') as $si) {
                    $parts = $si->xpath('.//*[local-name()="t"]');
                    $strings[] = implode('', array_map('strval', $parts));
                }
            }
            $book = $zip->getFromName('xl/workbook.xml');
            $rels = $zip->getFromName('xl/_rels/workbook.xml.rels');
            if ($book === false || $rels === false) {
                $this->fail('Workbook structure missing.');
            }
            $sheets = $this->xml($book)->xpath('//*[local-name()="sheet"]');
            if (! $sheets) {
                $this->fail('No worksheet found.');
            }
            $id = (string) $sheets[0]->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')['id'];
            $target = null;
            foreach ($this->xml($rels)->xpath('//*[local-name()="Relationship"]') as $rel) {
                if ((string) $rel['Id'] === $id && (string) $rel['TargetMode'] !== 'External') {
                    $target = (string) $rel['Target'];
                }
            }
            if (! $target || str_contains($target, '..') || str_contains($target, ':')) {
                $this->fail('Invalid worksheet reference.');
            }
            $sheet = $zip->getFromName(str_starts_with($target, '/') ? ltrim($target, '/') : 'xl/'.$target);
            if ($sheet === false) {
                $this->fail('Worksheet not found.');
            }
            $rows = [];
            foreach ($this->xml($sheet)->xpath('//*[local-name()="sheetData"]/*[local-name()="row"]') as $row) {
                $cells = [];
                foreach ($row->xpath('./*[local-name()="c"]') as $cell) {
                    if ($cell->xpath('./*[local-name()="f"]')) {
                        $this->fail('Formula cells are not supported; paste their values before uploading.');
                    }
                    if (! preg_match('/^([A-Z]+)[0-9]+$/', (string) $cell['r'], $m)) {
                        $this->fail('Invalid cell reference.');
                    }$col = 0;
                    foreach (str_split($m[1]) as $letter) {
                        $col = $col * 26 + ord($letter) - 64;
                    }$col--;
                    if ($col >= 50) {
                        $this->fail('Use at most 50 columns.');
                    }
                    $values = $cell->xpath('./*[local-name()="v"]');
                    $value = (string) ($values[0] ?? '');
                    $type = (string) $cell['t'];
                    if ($type === 's') {
                        $value = $strings[(int) $value] ?? '';
                    } elseif ($type === 'inlineStr') {
                        $value = implode('', array_map('strval', $cell->xpath('.//*[local-name()="t"]')));
                    }
                    $cells[$col] = $value;
                }
                if (! $cells) {
                    continue;
                }$dense = array_fill(0, max(array_keys($cells)) + 1, '');
                foreach ($cells as $col => $v) {
                    $dense[$col] = $v;
                }$this->check($dense, count($rows));
                $rows[] = $dense;
            }

            return $rows;
        } finally {
            $zip->close();
        }
    }

    private function check(array $row, int $count): void
    {
        if ($count > 10000 || count($row) > 50) {
            $this->fail('Maximum 10,000 contact rows and 50 columns per file.');
        }
        foreach ($row as $value) {
            if (! mb_check_encoding((string) $value, 'UTF-8') || mb_strlen((string) $value) > 2000 || str_contains((string) $value, "\0")) {
                $this->fail('Use UTF-8 text, no null bytes, and at most 2,000 characters per cell.');
            }
        }
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['file' => $message]);
    }
}
