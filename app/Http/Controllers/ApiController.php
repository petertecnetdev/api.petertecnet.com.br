<?php

namespace App\Http\Controllers;

use Illuminate\Http\Response;

abstract class ApiController extends Controller
{
    protected function sanitizeForJson($value)
    {
        if (is_null($value) || is_bool($value) || is_int($value) || is_float($value)) {
            return $value;
        }

        if (is_string($value)) {
            if (!mb_check_encoding($value, 'UTF-8')) {
                $value = mb_convert_encoding($value, 'UTF-8', 'UTF-8, ISO-8859-1, Windows-1252');
            }
            return iconv('UTF-8', 'UTF-8//IGNORE', $value);
        }

        if ($value instanceof \Illuminate\Support\Collection) {
            return $this->sanitizeForJson($value->toArray());
        }

        if ($value instanceof \JsonSerializable) {
            return $this->sanitizeForJson($value->jsonSerialize());
        }

        if ($value instanceof \Illuminate\Database\Eloquent\Model) {
            return $this->sanitizeForJson($value->toArray());
        }

        if (is_array($value)) {
            return array_map([$this, 'sanitizeForJson'], $value);
        }

        return $value;
    }

    protected function jsonUtf8($data, int $status = 200, array $headers = [])
    {
        return response()->json(
            $this->sanitizeForJson($data),
            $status,
            $headers,
            JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
        );
    }
}
