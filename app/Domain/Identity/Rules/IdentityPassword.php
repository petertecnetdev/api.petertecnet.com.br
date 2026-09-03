<?php

namespace App\Domain\Identity\Rules;

use App\Domain\Identity\Services\CompromisedPasswordService;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class IdentityPassword implements ValidationRule
{
    public function __construct(private readonly CompromisedPasswordService $compromisedPasswords)
    {
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $password = (string) $value;
        $min = max((int) config('identity.password.min_length', 8), 8);

        if (mb_strlen($password) < $min) {
            $fail("A senha deve ter pelo menos {$min} caracteres.");
            return;
        }

        $requirements = [
            'require_uppercase' => ['/\p{Lu}/u', 'uma letra maiúscula'],
            'require_lowercase' => ['/\p{Ll}/u', 'uma letra minúscula'],
            'require_number' => ['/\d/u', 'um número'],
            'require_symbol' => ['/[^\p{L}\p{N}\s]/u', 'um símbolo'],
        ];

        foreach ($requirements as $configKey => [$pattern, $description]) {
            if (config('identity.password.' . $configKey, true) && ! preg_match($pattern, $password)) {
                $fail('A senha deve conter ' . $description . '.');
                return;
            }
        }

        if ($this->compromisedPasswords->isCompromised($password)) {
            $fail('Esta senha aparece em vazamentos conhecidos. Escolha uma senha diferente.');
        }
    }
}
