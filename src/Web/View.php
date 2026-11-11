<?php

declare(strict_types=1);

namespace Newsstand\Web;

use Newsstand\Privacy\Obfuscator;

/**
 * Renders templates/*.php inside templates/layout.php.
 *
 * Templates get two escaping helpers and must use one of them for every value:
 *   $h(...)  for Newsstand's own strings (labels, numbers, group names);
 *   $x(...)  for anything that came from an article or the server's descriptions: it disguises addresses
 *            first, then escapes. $body(...) does the same and also links URLs.
 */
final class View
{
    public function __construct(private readonly string $dir, private readonly Obfuscator $obfuscator)
    {
    }

    /** @param array<string, mixed> $vars */
    public function page(string $template, array $vars): string
    {
        $content = $this->render($template, $vars);
        return $this->render('layout', $vars + ['content' => $content]);
    }

    /** @param array<string, mixed> $vars */
    public function render(string $template, array $vars): string
    {
        $obf = $this->obfuscator;
        $vars['h'] = static fn (string|int|null $s): string => Html::e($s);
        $vars['x'] = static fn (string|null $s): string => Html::e($obf->text((string) $s));
        $vars['body'] = static fn (string $s): string => Html::linkify($obf->text($s));
        $vars['view'] = $this;
        $file = $this->dir . '/' . $template . '.php';
        return (static function (string $__file, array $__vars): string {
            extract($__vars, EXTR_SKIP);
            ob_start();
            try {
                require $__file;
                return (string) ob_get_clean();
            } catch (\Throwable $e) {
                ob_end_clean();
                throw $e;
            }
        })($file, $vars);
    }
}
