<?php
declare(strict_types=1);

// Provide lightweight stub in root namespace if running in pure PHPUnit environment without WP
namespace {
    if (!class_exists('WP_REST_Response')) {
        class WP_REST_Response
        {
            public function __construct(private mixed $data = null, private int $status = 200)
            {
            }

            public function get_status(): int
            {
                return $this->status;
            }

            public function get_data(): mixed
            {
                return $this->data;
            }
        }
    }
}

namespace Anoofy\Woo\Tests\Rest {

use Anoofy\Woo\Rest\InboundResponse;
use PHPUnit\Framework\TestCase;

final class InboundResponseTest extends TestCase
{
    public function testUnauthorizedResponse(): void
    {
        $res = InboundResponse::unauthorized();
        $this->assertSame(401, $res->get_status());
        $data = $res->get_data();
        $this->assertFalse($data['success']);
        $this->assertSame('UNAUTHORIZED', $data['error_code']);
    }

    public function testInvalidResponse(): void
    {
        $res = InboundResponse::invalid('بيانات غير صالحة', ['body' => 'Malformed']);
        $this->assertSame(422, $res->get_status());
        $data = $res->get_data();
        $this->assertFalse($data['success']);
        $this->assertSame('بيانات غير صالحة', $data['message']);
        $this->assertSame(['body' => 'Malformed'], $data['errors']);
    }

    public function testIgnoredResponse(): void
    {
        $res = InboundResponse::ignored('unsupported event type');
        $this->assertSame(200, $res->get_status());
        $data = $res->get_data();
        $this->assertTrue($data['success']);
        $this->assertSame('ignored', $data['data']['result']);
        $this->assertSame('unsupported event type', $data['data']['reason']);
    }

    public function testOkResponseWithAndWithoutReason(): void
    {
        $resProcessed = InboundResponse::ok('processed');
        $this->assertSame(200, $resProcessed->get_status());
        $dataP = $resProcessed->get_data();
        $this->assertTrue($dataP['success']);
        $this->assertSame('processed', $dataP['data']['result']);
        $this->assertArrayNotHasKey('reason', $dataP['data']);

        $resDuplicate = InboundResponse::ok('duplicate', 'Event already processed');
        $this->assertSame(200, $resDuplicate->get_status());
        $dataD = $resDuplicate->get_data();
        $this->assertTrue($dataD['success']);
        $this->assertSame('duplicate', $dataD['data']['result']);
        $this->assertSame('Event already processed', $dataD['data']['reason']);
    }

    public function testInternalResponse(): void
    {
        $res = InboundResponse::internal();
        $this->assertSame(500, $res->get_status());
        $data = $res->get_data();
        $this->assertFalse($data['success']);
        $this->assertSame('INTERNAL', $data['error_code']);
    }

    public function testInboxRepositoryDoesNotReferenceOpsRepository(): void
    {
        $inboxRepoFile = file_get_contents(dirname(__DIR__, 2) . '/src/Data/InboxRepository.php');
        $this->assertStringNotContainsString(
            'OpsRepository',
            $inboxRepoFile,
            'InboxRepository must not know about or reference OpsRepository (SRP violation).'
        );
    }

    public function testAllMethodsInEngazInboundServiceAre30LinesOrFewer(): void
    {
        $serviceFile = dirname(__DIR__, 2) . '/src/Services/EngazInboundService.php';
        $lines = file($serviceFile, FILE_IGNORE_NEW_LINES);
        
        $currentMethod = null;
        $methodStart = 0;
        $braceDepth = 0;
        $inMethod = false;

        foreach ($lines as $lineNum => $line) {
            $trimmed = trim($line);
            if (preg_match('/^\s*(?:public|private|protected)\s+function\s+([a-zA-Z0-9_]+)\s*\(/', $line, $matches)) {
                $currentMethod = $matches[1];
                $methodStart = $lineNum;
                $braceDepth = 0;
                $inMethod = true;
            }

            if ($inMethod) {
                $braceDepth += substr_count($trimmed, '{') - substr_count($trimmed, '}');
                if ($braceDepth === 0 && str_contains($trimmed, '}')) {
                    $methodLength = ($lineNum - $methodStart) + 1;
                    $this->assertLessThanOrEqual(
                        30,
                        $methodLength,
                        sprintf('Method %s in EngazInboundService exceeds 30 lines (length: %d)', $currentMethod, $methodLength)
                    );
                    $inMethod = false;
                }
            }
        }
    }
}
}
