<?php

namespace Geodro\ErrorReport;

use BackedEnum;
use Closure;
use DateTimeInterface;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use ReflectionFunction;
use ReflectionMethod;
use Symfony\Component\HttpFoundation\Request;
use Throwable;
use UnitEnum;

// Renders the argument values of a stack frame. Values are kept as they were,
// except anything user related: the user model, the request, and any argument,
// key or attribute whose name says it holds a person's data.
class Arguments
{
    private const USER_WORDS = ['password', 'pass', 'pwd', 'secret', 'token', 'key', 'apikey', 'auth',
        'email', 'mail', 'phone', 'mobile', 'tel', 'name', 'firstname', 'lastname', 'surname', 'address',
        'street', 'city', 'zip', 'postal', 'postcode', 'birthday', 'birthdate', 'dob', 'ip', 'session',
        'cookie', 'card', 'cvv', 'cvc', 'iban', 'ssn', 'user', 'customer', 'client', 'account', 'login',
        'username', 'avatar', 'gender', 'lat', 'lng', 'latitude', 'longitude', 'location', 'remember'];

    /**
     * @return list<array{0: string, 1: string}> name and rendered value
     * @param array<string, mixed> $frame
     */
    public static function forFrame(array $frame): array
    {
        $names = self::parameterNames($frame);
        $out = [];
        foreach (array_slice($frame['args'] ?? [], 0, 10) as $i => $value) {
            $name = $names[$i] ?? ($names ? end($names).$i : (string) $i);
            $out[] = ['$'.$name, self::isUserRelated($name) ? '[user]' : self::render($value, 2)];
        }

        return $out;
    }

    public static function isUserRelated(string $name): bool
    {
        $words = preg_split('/[^a-z0-9]+/', strtolower((string) preg_replace('/([a-z0-9])([A-Z])/', '$1_$2', $name))) ?: [];

        return (bool) array_intersect($words, self::USER_WORDS);
    }

    private static function render(mixed $value, int $depth): string
    {
        return match (true) {
            $value === null => 'null',
            is_bool($value) => $value ? 'true' : 'false',
            is_int($value), is_float($value) => (string) $value,
            is_string($value) => "'".Anonymiser::text(mb_strimwidth($value, 0, 200, '…'))."'",
            is_array($value) => self::renderArray($value, $depth),
            $value instanceof Authenticatable => get_class($value).' [user]',
            $value instanceof Request => get_class($value).' [omitted]',
            $value instanceof Model => self::renderModel($value, $depth),
            $value instanceof Collection => get_class($value).'('.$value->count().') '.self::renderArray($value->take(3)->all(), $depth),
            $value instanceof BackedEnum => get_class($value).'::'.$value->name."({$value->value})",
            $value instanceof UnitEnum => get_class($value).'::'.$value->name,
            $value instanceof DateTimeInterface => get_class($value).' '.$value->format('Y-m-d H:i:s T'),
            $value instanceof Closure => 'Closure',
            is_object($value) => get_class($value),
            default => get_debug_type($value),
        };
    }

    /**
     * @param array<mixed> $items
     */
    private static function renderArray(array $items, int $depth): string
    {
        if ($depth <= 0) {
            return 'array('.count($items).')';
        }
        $parts = [];
        foreach (array_slice($items, 0, 10, true) as $key => $item) {
            $parts[] = (array_is_list($items) ? '' : "{$key}: ")
                .(is_string($key) && self::isUserRelated($key) ? '[user]' : self::render($item, $depth - 1));
        }

        return '['.implode(', ', $parts).(count($items) > 10 ? ', … '.count($items).' items' : '').']';
    }

    // Raw attributes only: casts, accessors and appends can run queries or code while the app is failing.
    private static function renderModel(Model $model, int $depth): string
    {
        $head = get_class($model).($model->exists ? ' #'.$model->getKey() : ' (not saved)');
        if ($depth <= 0) {
            return $head;
        }
        $attributes = array_diff_key($model->getAttributes(), array_flip($model->getHidden()), [$model->getKeyName() => true]);

        return $head.' {'.substr(self::renderArray(array_slice($attributes, 0, 15, true), $depth), 1, -1).'}';
    }

    /**
     * @return list<string>
     * @param array<string, mixed> $frame
     */
    private static function parameterNames(array $frame): array
    {
        try {
            $reflection = isset($frame['class']) && ! str_contains($frame['function'], '{closure')
                ? new ReflectionMethod($frame['class'], $frame['function'])
                : (is_string($frame['function']) && function_exists($frame['function']) ? new ReflectionFunction($frame['function']) : null);
        } catch (Throwable) {
            return [];
        }

        return $reflection ? array_map(fn ($p) => $p->getName(), $reflection->getParameters()) : [];
    }
}
