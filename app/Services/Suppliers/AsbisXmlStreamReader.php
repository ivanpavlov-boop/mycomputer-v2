<?php

namespace App\Services\Suppliers;

use RuntimeException;
use SimpleXMLElement;
use XMLReader;

class AsbisXmlStreamReader
{
    /**
     * @return array{rows: array<int, array<string, string>>, rows_scanned: int, completed: bool, raw_fields: array<int, string>}
     */
    public function read(string $path, string $rowElement, ?int $rowLimit = null): array
    {
        $rows = [];
        $result = $this->scan(
            $path,
            $rowElement,
            function (array $row) use (&$rows): void {
                $rows[] = $row;
            },
            $rowLimit
        );

        return [
            'rows' => $rows,
            ...$result,
        ];
    }

    /**
     * @param  callable(array<string, string>, int): void  $onRow
     * @return array{rows_scanned: int, completed: bool, raw_fields: array<int, string>}
     */
    public function scan(string $path, string $rowElement, callable $onRow, ?int $rowLimit = null): array
    {
        $reader = new XMLReader;
        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();

        if (! $reader->open($path, null, LIBXML_NONET | LIBXML_COMPACT | LIBXML_NOCDATA)) {
            libxml_use_internal_errors($previous);

            throw new RuntimeException('Unable to open the local XML source.');
        }

        $reader->setParserProperty(XMLReader::LOADDTD, false);
        $reader->setParserProperty(XMLReader::VALIDATE, false);
        $reader->setParserProperty(XMLReader::SUBST_ENTITIES, false);

        $rawFields = [];
        $rowsScanned = 0;
        $rowDepth = null;
        $completed = true;

        try {
            while ($reader->read()) {
                if ($reader->nodeType !== XMLReader::ELEMENT || strcasecmp($reader->localName, $rowElement) !== 0) {
                    continue;
                }

                $rowDepth ??= $reader->depth;

                if ($reader->depth !== $rowDepth) {
                    continue;
                }

                if ($rowLimit !== null && $rowsScanned >= $rowLimit) {
                    $completed = false;

                    break;
                }

                $xml = $reader->readOuterXml();
                $node = simplexml_load_string($xml, SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOCDATA);

                if (! $node instanceof SimpleXMLElement) {
                    throw new RuntimeException('Unable to parse an XML row.');
                }

                $row = $this->flatten($node);
                $rowsScanned++;
                $onRow($row, $rowsScanned);

                foreach (array_keys($row) as $field) {
                    $rawFields[$field] = true;
                }
            }

            $errors = libxml_get_errors();

            if ($errors !== []) {
                $fatal = collect($errors)->first(fn (\LibXMLError $error): bool => $error->level >= LIBXML_ERR_ERROR);

                if ($fatal instanceof \LibXMLError) {
                    throw new RuntimeException('Malformed XML was detected while streaming the local source.');
                }
            }
        } finally {
            $reader->close();
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        return [
            'rows_scanned' => $rowsScanned,
            'completed' => $completed,
            'raw_fields' => array_keys($rawFields),
        ];
    }

    /**
     * @return array<string, string>
     */
    private function flatten(SimpleXMLElement $node, string $prefix = ''): array
    {
        $row = [];

        foreach ($node->attributes() as $key => $value) {
            $row[trim($prefix.'attr_'.$key, '.')] = trim((string) $value);
        }

        foreach ($node->children() as $key => $child) {
            $field = trim($prefix.$key, '.');

            if ($child->children()->count() > 0) {
                $row = array_merge($row, $this->flatten($child, $field.'.'));

                continue;
            }

            $value = trim((string) $child);
            $row[$field] = array_key_exists($field, $row) && filled($row[$field])
                ? $row[$field].' | '.$value
                : $value;
        }

        return $row;
    }
}
