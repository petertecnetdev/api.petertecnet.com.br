<?php

namespace App\Services;

use App\Models\BrazilianMunicipality;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CutinappLocationService
{
    public function canonicalMunicipality(?int $cityId, ?string $uf = null): ?BrazilianMunicipality
    {
        if (! $cityId) return null;
        $city = BrazilianMunicipality::query()->find($cityId);
        if (! $city) throw ValidationException::withMessages(['city_id' => ['Selecione uma cidade válida da lista.']]);
        if ($uf && strtoupper(trim($uf)) !== $city->uf) {
            throw ValidationException::withMessages(['city_id' => ['A cidade selecionada não pertence à UF informada.']]);
        }
        return $city;
    }

    public function applyCanonicalCity(array &$data, bool $physicalLocation = true): void
    {
        if (! $physicalLocation) return;
        $cityId = isset($data['city_id']) ? (int) $data['city_id'] : null;
        if (! $cityId) {
            throw ValidationException::withMessages(['city_id' => ['Selecione a cidade pelas sugestões para garantir uma localização válida.']]);
        }
        $city = $this->canonicalMunicipality($cityId, $data['uf'] ?? null);
        $data['city_id'] = $city->ibge_code;
        $data['city'] = $city->name;
        $data['uf'] = $city->uf;
        $data['state'] = $city->uf;
    }

    public function normalizeCep(?string $cep): ?string
    {
        if ($cep === null || trim($cep) === '') return null;
        $digits = preg_replace('/\D+/', '', $cep);
        if (strlen($digits) !== 8) throw ValidationException::withMessages(['cep' => ['Informe um CEP brasileiro válido com 8 números.']]);
        return $digits;
    }

    public function normalizedName(string $name): string
    {
        return Str::lower(Str::ascii(trim($name)));
    }
}
