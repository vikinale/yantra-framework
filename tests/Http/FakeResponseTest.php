<?php
declare(strict_types=1);

namespace Tests\Http;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tests\Support\FakeResponse;

final class FakeResponseTest extends TestCase
{
    #[Test]
    public function jsonResponseSetsContentType(): void
    {
        $res = (new FakeResponse())->withStatus(201)->json(['ok' => true]);

        self::assertSame(201, $res->status());
        self::assertSame('application/json; charset=utf-8', $res->headers()['Content-Type']);
        self::assertSame(['ok' => true], $res->jsonBody());
    }
}
