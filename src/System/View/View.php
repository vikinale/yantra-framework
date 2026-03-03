<?php
declare(strict_types=1);
namespace System\View;

final class View
{
    private static ?ViewRenderer $instance = null;

    public static function setInstance(ViewRenderer $renderer): void { self::$instance = $renderer; }

    public static function render(string $view, array $data = []): string {
        return self::getInstance()->render($view, $data);
    }

    public static function layout(string $layout): void {
        self::getInstance()->setLayout($layout);
    }

    public static function section(string $name): void {
        self::getInstance()->section($name);
    }

    public static function endSection(): void {
        self::getInstance()->endSection();
    }

    public static function yield(string $name, string $default = ''): string {
        return self::getInstance()->yield($name, $default);
    }

    public static function partial(string $view, array $data = []): string {
        return self::getInstance()->partial($view, $data);
    }

    private static function getInstance(): ViewRenderer {
        if (self::$instance === null) {
            throw new \RuntimeException("View instance not set. Ensure Application::boot() has initialized it.");
        }
        return self::$instance;
    }
}
