<?php

namespace Tests\Unit;

use App\Services\MailProviderDetector;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MailProviderDetectorTest extends TestCase
{
    #[Test]
    public function it_detects_google_workspace_from_mx(): void
    {
        $result = (new MailProviderDetector())->detect([
            'mx' => [['host' => 'aspmx.l.google.com', 'priority' => 1]],
            'txt' => [],
            'spf' => null,
        ]);

        $this->assertSame('Google Workspace', $result['provider']);
        $this->assertSame('detected', $result['status']);
    }

    #[Test]
    public function it_detects_microsoft_365_from_mx(): void
    {
        $result = (new MailProviderDetector())->detect([
            'mx' => [['host' => 'contoso.mail.protection.outlook.com', 'priority' => 0]],
            'txt' => [],
            'spf' => 'v=spf1 include:spf.protection.outlook.com -all',
        ]);

        $this->assertSame('Microsoft 365', $result['provider']);
    }

    #[Test]
    public function it_marks_other_when_mx_is_unrelated(): void
    {
        $result = (new MailProviderDetector())->detect([
            'mx' => [['host' => 'mx.zoho.com', 'priority' => 10]],
            'txt' => [],
            'spf' => null,
        ]);

        $this->assertSame('Other', $result['provider']);
        $this->assertSame('detected', $result['status']);
    }

    #[Test]
    public function it_marks_not_detected_without_mx(): void
    {
        $result = (new MailProviderDetector())->detect([
            'mx' => [],
            'txt' => [],
            'spf' => null,
        ]);

        $this->assertSame('Not Detected', $result['provider']);
        $this->assertSame('not_detected', $result['status']);
    }
}
