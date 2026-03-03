<?php
declare(strict_types=1);

namespace System\Testing\Fixtures;

class FixtureManager
{
    private array $loaded = [];

    public function load(string $fixtureClass): void
    {
        if (isset($this->loaded[$fixtureClass])) {
            return;
        }

        if (!class_exists($fixtureClass)) {
            throw new \RuntimeException("Fixture class not found: {$fixtureClass}");
        }

        $fixture = new $fixtureClass();
        if (method_exists($fixture, 'load')) {
            $fixture->load();
        }

        $this->loaded[$fixtureClass] = true;
    }

    public function clear(): void
    {
        // Logic to clear fixtures if they implement a down/unload method
        $this->loaded = [];
    }
}
