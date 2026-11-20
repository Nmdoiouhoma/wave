<?php

declare(strict_types=1);

namespace App\Enum;

/** `SupervisionLevel` (FB-34, W-88): health degree, worst-wins order ok < attention < alerte < critique. */
enum SupervisionLevel: string
{
    case Ok = 'ok';
    case Attention = 'attention';
    case Alerte = 'alerte';
    case Critique = 'critique';

    public function rank(): int
    {
        return match ($this) {
            self::Ok => 0,
            self::Attention => 1,
            self::Alerte => 2,
            self::Critique => 3,
        };
    }

    /** Worst of the given levels; `ok` when none. */
    public static function worst(self ...$levels): self
    {
        $worst = self::Ok;
        foreach ($levels as $level) {
            if ($level->rank() > $worst->rank()) {
                $worst = $level;
            }
        }

        return $worst;
    }

    /** Level of the alert severity (`high`/`medium`/`low`, DATA_MODEL §16); unknown → ok. */
    public static function fromSeverity(string $severity): self
    {
        return match ($severity) {
            'high' => self::Critique,
            'medium' => self::Alerte,
            'low' => self::Attention,
            default => self::Ok,
        };
    }
}
