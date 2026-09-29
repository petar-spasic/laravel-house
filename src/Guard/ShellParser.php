<?php

namespace PetarSpasic\Kanban\Guard;

use UnexpectedValueException;

/**
 * Splits a bash command line into the simple commands it would run, with the
 * directory each one runs in (null = unknown). Heredoc bodies are data; command
 * substitutions, backticks, process substitutions, `sh -c`, xargs, find -exec and
 * the usual wrappers are followed. Anything that cannot be verified becomes an
 * `unverifiable` entry.
 *
 * @phpstan-type Word array{t: 'w', v: string, dyn: bool, q: bool, plain: string, subs: list<list<array<string, mixed>>>}
 * @phpstan-type Cmd array{words: list<Word>, assigns: list<array{name: string, word: Word}>, redirs: list<array{t: 'r', v: string, fd: string, target: Word}>, dir: ?string, extra: bool, sudo: bool, program: string}
 */
final class ShellParser
{
    private const RESERVED = ['if', 'then', 'else', 'elif', 'fi', 'do', 'done', 'while', 'until', '{', '}', '!', 'time'];

    private const SHELLS = ['sh', 'bash', 'dash', 'zsh', 'ksh'];

    private int $i = 0;

    private int $n;

    /** @var list<array{delim: string, quoted: bool, strip: bool}> */
    private array $heredocs = [];

    private function __construct(private string $s, private string $home)
    {
        $this->n = strlen($s);
    }

    /**
     * @return array{cmds: list<array<string, mixed>>, error: ?string}
     */
    public static function parse(string $source, ?string $dir, string $home): array
    {
        $cmds = [];

        try {
            (new self($source, $home))->nested($source, $dir, $cmds);
        } catch (UnexpectedValueException $e) {
            return ['cmds' => $cmds, 'error' => $e->getMessage()];
        }

        return ['cmds' => $cmds, 'error' => null];
    }

    /**
     * Resolves a word to an absolute, lexically normalised path; null when it depends on runtime values.
     *
     * @param  array<string, mixed>  $word
     */
    public static function path(array $word, ?string $dir, string $home): ?string
    {
        $v = $word['v'];

        if ($word['dyn']) {
            $v = preg_replace_callback('/\$\{?(HOME|PWD|TMPDIR|CLAUDE_PROJECT_DIR)\}?/', function (array $m) use ($home, $dir): string {
                return match ($m[1]) {
                    'HOME' => $home,
                    'PWD' => $dir ?? '$PWD',
                    'TMPDIR' => getenv('TMPDIR') ?: '/tmp',
                    default => getenv('CLAUDE_PROJECT_DIR') ?: '$CLAUDE_PROJECT_DIR',
                };
            }, $v);

            if (str_contains($v, '$') || str_contains($v, '`')) {
                return null;
            }
        }

        if ($v === '') {
            return null;
        }

        if (str_starts_with($word['plain'], '~') && ($v === '~' || str_starts_with($v, '~/'))) {
            $v = $home.substr($v, 1);
        }

        if ($v[0] !== '/') {
            if ($dir === null) {
                return null;
            }
            $v = $dir.'/'.$v;
        }

        return self::normalize($v);
    }

    public static function normalize(string $path): string
    {
        $parts = [];

        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                array_pop($parts);

                continue;
            }
            $parts[] = $segment;
        }

        return '/'.implode('/', $parts);
    }

    /**
     * @param  list<array<string, mixed>>  $out
     */
    private function nested(string $source, ?string $dir, array &$out): void
    {
        $parser = new self($source, $this->home);
        $parser->walk($parser->lex(false), $dir, $out);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function lex(bool $untilParen): array
    {
        $tokens = [];
        $depth = 0;

        while (true) {
            while ($this->i < $this->n && ($this->s[$this->i] === ' ' || $this->s[$this->i] === "\t" || substr($this->s, $this->i, 2) === "\\\n")) {
                $this->i += $this->s[$this->i] === '\\' ? 2 : 1;
            }

            if ($this->i >= $this->n) {
                if ($untilParen) {
                    throw new UnexpectedValueException('unbalanced $( )');
                }
                $this->heredocs = [];

                return $tokens;
            }

            $c = $this->s[$this->i];
            $next = $this->s[$this->i + 1] ?? '';

            if ($c === '#') {
                $newline = strpos($this->s, "\n", $this->i);
                $this->i = $newline === false ? $this->n : $newline;

                continue;
            }

            if ($c === "\n") {
                $this->i++;
                $tokens[] = ['t' => 'op', 'v' => "\n"];
                $this->readHeredocs($tokens);

                continue;
            }

            if ($c === '(') {
                $this->i++;
                $depth++;
                $tokens[] = ['t' => 'op', 'v' => '('];

                continue;
            }

            if ($c === ')') {
                $this->i++;
                if ($depth === 0 && $untilParen) {
                    return $tokens;
                }
                $depth--;
                $tokens[] = ['t' => 'op', 'v' => ')'];

                continue;
            }

            if (($c === '<' || $c === '>') && $next === '(') {
                $this->i += 2;
                $tokens[] = ['t' => 'w', 'v' => '/dev/fd/63', 'dyn' => true, 'q' => false, 'plain' => '', 'subs' => [$this->lex(true)]];

                continue;
            }

            if (preg_match('/(\d+|\{\w+\})?(<<<|<<-|<<|<>|<&|>>|>\||>&|&>>|&>|<|>)/A', $this->s, $m, 0, $this->i)) {
                $this->i += strlen($m[0]);
                $tokens[] = $this->redirect($m[2], $m[1]);

                continue;
            }

            if (preg_match('/;;&|;;|;&|;|&&|&|\|\||\|&|\|/A', $this->s, $m, 0, $this->i)) {
                $this->i += strlen($m[0]);
                $tokens[] = ['t' => 'op', 'v' => $m[0]];

                continue;
            }

            $tokens[] = $this->word() ?? throw new UnexpectedValueException('unexpected character '.$c);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function redirect(string $op, string $fd): array
    {
        while ($this->i < $this->n && ($this->s[$this->i] === ' ' || $this->s[$this->i] === "\t")) {
            $this->i++;
        }

        $target = $this->word() ?? throw new UnexpectedValueException("redirection {$op} without a target");

        if ($op === '<<' || $op === '<<-') {
            $this->heredocs[] = ['delim' => $target['v'], 'quoted' => $target['q'], 'strip' => $op === '<<-'];
        }

        return ['t' => 'r', 'v' => $op, 'fd' => $fd, 'target' => $target];
    }

    /**
     * @param  list<array<string, mixed>>  $tokens
     */
    private function readHeredocs(array &$tokens): void
    {
        foreach ($this->heredocs as $heredoc) {
            $body = '';

            while ($this->i < $this->n) {
                $newline = strpos($this->s, "\n", $this->i);
                $line = $newline === false ? substr($this->s, $this->i) : substr($this->s, $this->i, $newline - $this->i);
                $this->i = $newline === false ? $this->n : $newline + 1;

                if (($heredoc['strip'] ? ltrim($line, "\t") : $line) === $heredoc['delim']) {
                    break;
                }
                $body .= $line."\n";
            }

            if (! $heredoc['quoted'] && $body !== '') {
                $subs = (new self($body, $this->home))->expansions();
                if ($subs !== []) {
                    $tokens[] = ['t' => 'hd', 'subs' => $subs];
                }
            }
        }

        $this->heredocs = [];
    }

    /**
     * Command substitutions inside an unquoted heredoc body.
     *
     * @return list<list<array<string, mixed>>>
     */
    private function expansions(): array
    {
        $v = '';
        $dyn = false;
        $subs = [];

        while ($this->i < $this->n) {
            $c = $this->s[$this->i];
            if ($c === '\\') {
                $this->i += 2;
            } elseif ($c === '$') {
                $this->dollar($v, $dyn, $subs);
            } elseif ($c === '`') {
                $this->backtick($v, $dyn, $subs);
            } else {
                $this->i++;
            }
        }

        return $subs;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function word(): ?array
    {
        $start = $this->i;
        $v = '';
        $plain = '';
        $open = true;
        $dyn = false;
        $quoted = false;
        $subs = [];

        while ($this->i < $this->n) {
            $c = $this->s[$this->i];

            if (str_contains(" \t\n;&|<>()", $c)) {
                break;
            }

            if ($c === '\\') {
                $next = $this->s[$this->i + 1] ?? '';
                $this->i += 2;
                if ($next !== "\n") {
                    $v .= $next;
                    $quoted = true;
                    $open = false;
                }

                continue;
            }

            if ($c === "'") {
                $end = strpos($this->s, "'", $this->i + 1);
                if ($end === false) {
                    throw new UnexpectedValueException('unbalanced quotes');
                }
                $v .= substr($this->s, $this->i + 1, $end - $this->i - 1);
                $this->i = $end + 1;
                $quoted = true;
                $open = false;

                continue;
            }

            if ($c === '"' || ($c === '$' && ($this->s[$this->i + 1] ?? '') === '"')) {
                $this->i += $c === '$' ? 2 : 1;
                $this->doubleQuoted($v, $dyn, $subs);
                $quoted = true;
                $open = false;

                continue;
            }

            if ($c === '$' && ($this->s[$this->i + 1] ?? '') === "'") {
                $this->ansiC($v);
                $quoted = true;
                $open = false;

                continue;
            }

            if ($c === '$') {
                $this->dollar($v, $dyn, $subs);
                $open = false;

                continue;
            }

            if ($c === '`') {
                $this->backtick($v, $dyn, $subs);
                $open = false;

                continue;
            }

            $v .= $c;
            if ($open) {
                $plain .= $c;
            }
            $this->i++;
        }

        if ($this->i === $start) {
            return null;
        }

        return ['t' => 'w', 'v' => $v, 'dyn' => $dyn, 'q' => $quoted, 'plain' => $plain, 'subs' => $subs];
    }

    /**
     * @param  list<list<array<string, mixed>>>  $subs
     */
    private function doubleQuoted(string &$v, bool &$dyn, array &$subs): void
    {
        while (true) {
            if ($this->i >= $this->n) {
                throw new UnexpectedValueException('unbalanced quotes');
            }

            $c = $this->s[$this->i];

            if ($c === '"') {
                $this->i++;

                return;
            }

            if ($c === '\\') {
                $next = $this->s[$this->i + 1] ?? '';
                if ($next !== '' && str_contains("\$`\"\\\n", $next)) {
                    if ($next !== "\n") {
                        $v .= $next;
                    }
                    $this->i += 2;
                } else {
                    $v .= '\\';
                    $this->i++;
                }

                continue;
            }

            if ($c === '$') {
                $this->dollar($v, $dyn, $subs);
            } elseif ($c === '`') {
                $this->backtick($v, $dyn, $subs);
            } else {
                $v .= $c;
                $this->i++;
            }
        }
    }

    private function ansiC(string &$v): void
    {
        $this->i += 2;
        $simple = ['n' => "\n", 't' => "\t", 'r' => "\r", 'e' => "\e", 'E' => "\e", 'a' => "\x07", 'b' => "\x08", 'f' => "\f", 'v' => "\v"];

        while (true) {
            if ($this->i >= $this->n) {
                throw new UnexpectedValueException('unbalanced quotes');
            }

            $c = $this->s[$this->i];

            if ($c === "'") {
                $this->i++;

                return;
            }

            if ($c !== '\\') {
                $v .= $c;
                $this->i++;

                continue;
            }

            if (preg_match('/x([0-9a-fA-F]{1,2})|([0-7]{1,3})|u([0-9a-fA-F]{1,4})/A', $this->s, $m, 0, $this->i + 1)) {
                $v .= match (true) {
                    ($m[1] ?? '') !== '' => chr(hexdec($m[1])),
                    ($m[2] ?? '') !== '' => chr(octdec($m[2]) & 0xFF),
                    default => mb_chr((int) hexdec($m[3])),
                };
                $this->i += 1 + strlen($m[0]);

                continue;
            }

            $next = $this->s[$this->i + 1] ?? '';
            $v .= $simple[$next] ?? $next;
            $this->i += 2;
        }
    }

    /**
     * @param  list<list<array<string, mixed>>>  $subs
     */
    private function dollar(string &$v, bool &$dyn, array &$subs): void
    {
        $next = $this->s[$this->i + 1] ?? '';
        $start = $this->i;

        if ($next === '(' && ($this->s[$this->i + 2] ?? '') === '(') {
            $this->i += 3;
            $depth = 2;
            while ($this->i < $this->n && $depth > 0) {
                $ch = $this->s[$this->i++];
                $depth += $ch === '(' ? 1 : ($ch === ')' ? -1 : 0);
            }
            if ($depth > 0) {
                throw new UnexpectedValueException('unbalanced $(( ))');
            }
            $v .= substr($this->s, $start, $this->i - $start);
            $dyn = true;

            return;
        }

        if ($next === '(') {
            $this->i += 2;
            $subs[] = $this->lex(true);
            $v .= '$(…)';
            $dyn = true;

            return;
        }

        if ($next === '{') {
            $this->i += 2;
            $depth = 1;
            while ($this->i < $this->n && $depth > 0) {
                $ch = $this->s[$this->i++];
                if ($ch === '\\') {
                    $this->i++;
                } else {
                    $depth += $ch === '{' ? 1 : ($ch === '}' ? -1 : 0);
                }
            }
            if ($depth > 0) {
                throw new UnexpectedValueException('unbalanced ${ }');
            }
            $v .= substr($this->s, $start, $this->i - $start);
            $dyn = true;

            return;
        }

        if (preg_match('/[A-Za-z_]\w*|[0-9@*#?$!-]/A', $this->s, $m, 0, $this->i + 1)) {
            $v .= '$'.$m[0];
            $this->i += 1 + strlen($m[0]);
            $dyn = true;

            return;
        }

        $v .= '$';
        $this->i++;
    }

    /**
     * @param  list<list<array<string, mixed>>>  $subs
     */
    private function backtick(string &$v, bool &$dyn, array &$subs): void
    {
        $this->i++;
        $inner = '';

        while (true) {
            if ($this->i >= $this->n) {
                throw new UnexpectedValueException('unbalanced backticks');
            }

            $c = $this->s[$this->i];

            if ($c === '\\' && in_array($this->s[$this->i + 1] ?? '', ['`', '\\', '$'], true)) {
                $inner .= $this->s[$this->i + 1];
                $this->i += 2;

                continue;
            }

            $this->i++;

            if ($c === '`') {
                break;
            }
            $inner .= $c;
        }

        $subs[] = (new self($inner, $this->home))->lex(false);
        $v .= '`…`';
        $dyn = true;
    }

    /**
     * @param  list<array<string, mixed>>  $tokens
     * @param  list<array<string, mixed>>  $out
     */
    private function walk(array $tokens, ?string $dir, array &$out): void
    {
        $cmd = self::blank();
        $scopes = [];
        $case = [];
        $caseHeader = 0;
        $skipList = false;
        $skipName = false;
        $count = count($tokens);

        for ($k = 0; $k < $count; $k++) {
            $token = $tokens[$k];

            foreach ([...$token['subs'] ?? [], ...$token['target']['subs'] ?? []] as $sub) {
                $this->walk($sub, $dir, $out);
            }

            if ($token['t'] === 'hd') {
                continue;
            }

            $patterns = $case !== [] && end($case);

            if ($token['t'] === 'op') {
                $op = $token['v'];

                if ($patterns) {
                    if ($op === ')') {
                        $case[count($case) - 1] = false;
                    }

                    continue;
                }

                if ($op === '(' && count($cmd['words']) === 1 && ($tokens[$k + 1]['t'] ?? '') === 'op' && $tokens[$k + 1]['v'] === ')') {
                    $cmd = self::blank();
                    $k++;

                    continue;
                }

                $dir = $this->finish($cmd, $dir, $out);
                $cmd = self::blank();
                $skipList = false;

                if ($op === '(') {
                    $scopes[] = $dir;
                } elseif ($op === ')') {
                    if ($scopes === []) {
                        throw new UnexpectedValueException('unbalanced parentheses');
                    }
                    $dir = array_pop($scopes);
                } elseif ($case !== [] && in_array($op, [';;', ';&', ';;&'], true)) {
                    $case[count($case) - 1] = true;
                }

                continue;
            }

            if ($token['t'] === 'r') {
                if (! $patterns && ! $skipList) {
                    $cmd['redirs'][] = $token;
                }

                continue;
            }

            $reserved = ! $token['q'] && ! $token['dyn'] ? $token['v'] : null;

            if ($caseHeader > 0) {
                if (--$caseHeader === 0) {
                    $case[] = true;
                }

                continue;
            }

            if ($patterns) {
                if ($reserved === 'esac') {
                    array_pop($case);
                }

                continue;
            }

            if ($skipList) {
                continue;
            }

            if ($skipName) {
                $skipName = false;

                continue;
            }

            if ($cmd['words'] === []) {
                if ($reserved === 'case') {
                    $caseHeader = 2;

                    continue;
                }
                if ($reserved === 'esac' && $case !== []) {
                    array_pop($case);

                    continue;
                }
                if ($reserved === 'for' || $reserved === 'select') {
                    $skipList = true;

                    continue;
                }
                if ($reserved === 'function') {
                    $skipName = true;

                    continue;
                }
                if ($reserved !== null && in_array($reserved, self::RESERVED, true)) {
                    continue;
                }
                if (preg_match('/^[A-Za-z_]\w*(\[[^]]*\])?\+?=/', $token['plain'])) {
                    $cmd['assigns'][] = ['name' => (string) strstr($token['v'], '=', true), 'word' => $token];

                    continue;
                }
            }

            $cmd['words'][] = $token;
        }

        $this->finish($cmd, $dir, $out);

        if ($scopes !== []) {
            throw new UnexpectedValueException('unbalanced parentheses');
        }
    }

    /**
     * @return array<string, mixed>
     */
    private static function blank(): array
    {
        return ['words' => [], 'assigns' => [], 'redirs' => [], 'dir' => null, 'extra' => false, 'sudo' => false, 'program' => ''];
    }

    /**
     * @param  array<string, mixed>  $cmd
     * @param  list<array<string, mixed>>  $out
     */
    private function finish(array $cmd, ?string $dir, array &$out): ?string
    {
        if ($cmd['words'] === [] && $cmd['redirs'] === [] && $cmd['assigns'] === []) {
            return $dir;
        }

        $cmd['dir'] = $dir;

        return $this->expand($cmd, $out);
    }

    /**
     * Unwraps wrappers, records the command and returns the directory after it (cd/pushd).
     *
     * @param  array<string, mixed>  $cmd
     * @param  list<array<string, mixed>>  $out
     */
    private function expand(array $cmd, array &$out): ?string
    {
        $dir = $cmd['dir'];

        while ($cmd['words'] !== []) {
            $first = $cmd['words'][0];

            if ($first['dyn']) {
                $out[] = ['unverifiable' => 'a $VARIABLE or substitution as the command', 'dir' => $dir];

                return $dir;
            }

            $program = basename($first['v']);
            $args = array_slice($cmd['words'], 1);

            if (in_array($program, ['eval', 'source', '.'], true)) {
                $out[] = ['unverifiable' => "`{$program}`", 'dir' => $dir];

                return $dir;
            }

            $rest = match ($program) {
                'env' => $this->unwrapEnv($args, $cmd),
                'command' => array_filter($args, fn (array $a): bool => in_array($a['v'], ['-v', '-V'], true)) ? null : self::options($args, []),
                'builtin', 'nohup', 'setsid' => self::options($args, []),
                'exec' => self::options($args, ['-a']),
                'nice' => self::options($args, ['-n', '--adjustment']),
                'time' => self::options($args, ['-f', '--format', '-o', '--output']),
                'stdbuf' => self::options($args, ['-i', '-o', '-e', '--input', '--output', '--error']),
                'ionice' => array_filter($args, fn (array $a): bool => in_array($a['v'], ['-p', '-P', '-u'], true)) ? null : self::options($args, ['-c', '-n', '--class', '--classdata']),
                'timeout' => array_slice(self::options($args, ['-s', '--signal', '-k', '--kill-after']), 1),
                'sudo', 'doas' => self::options($args, ['-u', '-g', '-p', '-C', '-h', '-r', '-t', '-U', '-D', '-R', '-T', '--user', '--group', '--prompt', '--chdir']),
                'xargs' => self::options($args, ['-I', '-L', '-n', '-P', '-s', '-d', '-E', '-a', '--arg-file', '--delimiter', '--max-args', '--max-procs', '--max-chars', '--max-lines', '--eof']),
                'flock' => $this->unwrapFlock($args, $cmd, $out),
                'watch' => $this->unwrapWatch($args, $cmd, $out),
                'find' => $this->unwrapFind($args, $cmd, $out),
                default => in_array($program, self::SHELLS, true) ? $this->unwrapShell($args, $cmd, $out) : null,
            };

            if ($rest === false) {
                return $dir;
            }

            if ($rest === null) {
                break;
            }

            if ($program === 'sudo' || $program === 'doas') {
                $cmd['sudo'] = true;
                if ($rest === []) {
                    break;
                }
            }

            if ($program === 'xargs') {
                $cmd['extra'] = true;
                $rest = $rest === [] ? [['t' => 'w', 'v' => 'echo', 'dyn' => false, 'q' => false, 'plain' => 'echo', 'subs' => []]] : $rest;
            }

            $cmd['words'] = array_values($rest);
        }

        $cmd['program'] = $cmd['words'] === [] ? '' : basename($cmd['words'][0]['v']);
        $out[] = $cmd;

        return match ($cmd['program']) {
            'cd' => $this->cd(array_slice($cmd['words'], 1), $dir),
            'pushd', 'popd' => null,
            default => $dir,
        };
    }

    /**
     * @param  list<array<string, mixed>>  $args
     */
    private function cd(array $args, ?string $dir): ?string
    {
        $args = self::options($args, []);

        if ($args === []) {
            return $this->home;
        }

        return $args[0]['v'] === '-' ? null : self::path($args[0], $dir, $this->home);
    }

    /**
     * Drops leading options; `$withValue` options also consume the next word.
     *
     * @param  list<array<string, mixed>>  $args
     * @param  list<string>  $withValue
     * @return list<array<string, mixed>>
     */
    private static function options(array $args, array $withValue): array
    {
        $k = 0;
        $count = count($args);

        while ($k < $count) {
            $a = $args[$k]['v'];

            if ($a === '--') {
                $k++;
                break;
            }
            if ($a === '-' || ! str_starts_with($a, '-')) {
                break;
            }
            $k += in_array($a, $withValue, true) ? 2 : 1;
        }

        return array_slice($args, $k);
    }

    /**
     * @param  list<array<string, mixed>>  $args
     * @param  array<string, mixed>  $cmd
     * @return list<array<string, mixed>>|false
     */
    private function unwrapEnv(array $args, array &$cmd): array|false
    {
        $k = 0;
        $count = count($args);

        while ($k < $count) {
            $a = $args[$k];

            if (in_array($a['v'], ['-S', '--split-string'], true) || str_starts_with($a['v'], '--split-string=') || (str_starts_with($a['v'], '-S') && ! str_starts_with($a['v'], '--'))) {
                throw new UnexpectedValueException('env -S');
            }
            if (in_array($a['v'], ['-C', '--chdir'], true)) {
                $cmd['dir'] = isset($args[$k + 1]) ? self::path($args[$k + 1], $cmd['dir'], $this->home) : null;
                $k += 2;

                continue;
            }
            if (in_array($a['v'], ['-u', '--unset'], true)) {
                $k += 2;

                continue;
            }
            if ($a['v'] === '--') {
                $k++;

                continue;
            }
            if (str_starts_with($a['v'], '-') && $a['v'] !== '-') {
                $k++;

                continue;
            }
            if (preg_match('/^[A-Za-z_]\w*=/', $a['v'])) {
                $cmd['assigns'][] = ['name' => (string) strstr($a['v'], '=', true), 'word' => $a];
                $k++;

                continue;
            }
            break;
        }

        return array_slice($args, $k);
    }

    /**
     * @param  list<array<string, mixed>>  $args
     * @param  array<string, mixed>  $cmd
     * @param  list<array<string, mixed>>  $out
     * @return list<array<string, mixed>>|false|null
     */
    private function unwrapShell(array $args, array $cmd, array &$out): array|false|null
    {
        $command = false;
        $stdin = false;
        $k = 0;
        $count = count($args);

        while ($k < $count) {
            $a = $args[$k]['v'];

            if ($a === '--') {
                $k++;
                break;
            }
            if (in_array($a, ['-o', '+o', '-O', '+O', '--rcfile', '--init-file'], true)) {
                $k += 2;

                continue;
            }
            if (preg_match('/^[-+][a-zA-Z]+$/', $a)) {
                $command = $command || ($a[0] === '-' && str_contains($a, 'c'));
                $stdin = $stdin || str_contains($a, 's');
                $k++;

                continue;
            }
            if (str_starts_with($a, '--')) {
                $k++;

                continue;
            }
            break;
        }

        if ($command) {
            if (! isset($args[$k])) {
                throw new UnexpectedValueException('sh -c without a command');
            }
            $this->nested($args[$k]['v'], $cmd['dir'], $out);

            return false;
        }

        if (! isset($args[$k]) || $stdin) {
            throw new UnexpectedValueException('a shell reading commands from stdin');
        }

        return null;
    }

    /**
     * @param  list<array<string, mixed>>  $args
     * @param  array<string, mixed>  $cmd
     * @param  list<array<string, mixed>>  $out
     * @return list<array<string, mixed>>|false|null
     */
    private function unwrapFlock(array $args, array $cmd, array &$out): array|false|null
    {
        $positional = [];
        $count = count($args);

        for ($k = 0; $k < $count; $k++) {
            $a = $args[$k]['v'];

            if (in_array($a, ['-c', '--command'], true)) {
                if (! isset($args[$k + 1])) {
                    throw new UnexpectedValueException('flock -c without a command');
                }
                $this->nested($args[$k + 1]['v'], $cmd['dir'], $out);

                return false;
            }
            if ($positional === [] && in_array($a, ['-w', '--wait', '--timeout', '-E', '--conflict-exit-code'], true)) {
                $k++;

                continue;
            }
            if ($positional === [] && str_starts_with($a, '-')) {
                continue;
            }
            $positional[] = $args[$k];
        }

        return count($positional) > 1 ? array_slice($positional, 1) : null;
    }

    /**
     * @param  list<array<string, mixed>>  $args
     * @param  array<string, mixed>  $cmd
     * @param  list<array<string, mixed>>  $out
     * @return list<array<string, mixed>>|false|null
     */
    private function unwrapWatch(array $args, array $cmd, array &$out): array|false|null
    {
        $rest = self::options($args, ['-n', '--interval', '-q', '--equexit']);

        if (count($rest) === 1) {
            $this->nested($rest[0]['v'], $cmd['dir'], $out);

            return false;
        }

        return $rest === [] ? null : $rest;
    }

    /**
     * Records each -exec/-execdir/-ok command separately and leaves find with its own arguments.
     *
     * @param  list<array<string, mixed>>  $args
     * @param  array<string, mixed>  $cmd
     * @param  list<array<string, mixed>>  $out
     */
    private function unwrapFind(array $args, array &$cmd, array &$out): null
    {
        $count = count($args);
        $k = 0;
        $kept = [$cmd['words'][0]];

        while ($k < $count && (in_array($args[$k]['v'], ['-H', '-L', '-P'], true) || preg_match('/^-O\d$/', $args[$k]['v']))) {
            $kept[] = $args[$k++];
        }

        $base = null;
        while ($k < $count && ! preg_match('/^[-(!]/', $args[$k]['v'])) {
            $base ??= $args[$k];
            $kept[] = $args[$k++];
        }
        $base ??= ['t' => 'w', 'v' => '.', 'dyn' => false, 'q' => false, 'plain' => '.', 'subs' => []];

        for (; $k < $count; $k++) {
            if (! in_array($args[$k]['v'], ['-exec', '-execdir', '-ok', '-okdir'], true)) {
                $kept[] = $args[$k];

                continue;
            }

            $words = [];
            for ($k++; $k < $count && ! in_array($args[$k]['v'], [';', '+'], true); $k++) {
                $word = $args[$k];
                if (str_contains($word['v'], '{}')) {
                    $word['v'] = str_replace('{}', $base['v'], $word['v']);
                    $word['plain'] = str_replace('{}', $base['plain'], $word['plain']);
                    $word['dyn'] = $word['dyn'] || $base['dyn'];
                }
                $words[] = $word;
            }

            if ($k >= $count || $words === []) {
                throw new UnexpectedValueException('find -exec without its terminating ; or +');
            }

            $this->expand([...self::blank(), 'words' => $words, 'dir' => $cmd['dir']], $out);
        }

        $cmd['words'] = $kept;

        return null;
    }
}
