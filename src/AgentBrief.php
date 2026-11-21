<?php

namespace Geodro\ErrorReport;

// Instructions for the coding agent the report is pasted into, built from what the
// report knows, so each step names the actual release, files and way to reproduce.
class AgentBrief
{
    /**
     * @param array<string, mixed> $entry
     */
    public static function for(array $entry): string
    {
        $lines = [self::intro($entry)];
        foreach (self::steps($entry) as $i => $step) {
            $lines[] = ($i + 1).'. '.$step;
        }

        return implode("\n", $lines)."\n";
    }

    /**
     * @param array<string, mixed> $entry
     */
    public static function intro(array $entry): string
    {
        $times = $entry['count'] === 1 ? '1 time' : "{$entry['count']} times";

        return "For the agent fixing this: it happened in production {$times}, last at {$entry['last_seen']}.";
    }

    /**
     * @return list<string>
     * @param array<string, mixed> $entry
     */
    public static function steps(array $entry): array
    {
        $o = $entry['last'];

        return [
            self::code($o),
            'Reproduce: '.self::reproduce($o['context']).'.'.self::cause($o['previous']),
            'Values: [user], [email], [token], [number], [query] and similar were removed for privacy. Use factory stand-ins, never real customer data.',
            'Fix the cause: explain why the values were what they were. No try/catch, null check or @ that only hides the error.',
            'Prove it: a test that fails first, then the fix.',
            'Report back: cause, fix, test, and whether production data also needs a repair. Never connect to the production server yourself.',
        ];
    }

    /**
     * @param array<string, mixed> $o
     */
    private static function code(array $o): string
    {
        if (! $o['release']) {
            return 'Code: the release is unknown (set APP_RELEASE on deploy to record it). Find the commit that was live when it last happened, make a lerd worktree from it, not the main checkout.';
        }
        $files = array_unique(array_map(
            fn ($at) => preg_replace('/:\d+.*$/', '', $at),
            array_merge([$o['error']['at']], $o['error']['frames']),
        ));
        $files = implode(' ', array_slice(array_filter($files, fn ($f) => ! str_starts_with($f, '/')), 0, 5));

        return "Code: it broke at release {$o['release']}. Make a lerd worktree from that commit, not the main checkout, and run git log {$o['release']}..HEAD -- {$files} first: it may already be fixed.";
    }

    /**
     * @param array<string, mixed> $c
     */
    private static function reproduce(array $c): string
    {
        return match ($c['type']) {
            'job' => "dispatch {$c['job']} on the local lerd site",
            'cli' => 'run php artisan '.($c['command'] ?? '<the command>').' on the local lerd site',
            default => $c['route']
                ? "send {$c['method']} {$c['route']} to the local lerd site".(str_contains($c['route'], '{') ? ', filling in the parameters' : '')." as a {$c['auth']} visitor"
                : "send a {$c['method']} request that matches no route to the local lerd site",
        };
    }

    /**
     * @param list<array<string, mixed>> $previous
     */
    private static function cause(array $previous): string
    {
        return $previous ? ' Start from the deepest cause, '.end($previous)['class'].'.' : '';
    }
}
