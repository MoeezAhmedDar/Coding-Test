<?php

use PHPUnit\Framework\TestCase;
use App\Helpers\TeHelper;
use Carbon\Carbon;

class TeHelperTest extends TestCase
{
    public function testWillExpireAtWithin90Minutes()
    {
        $dueTime = '2023-08-31 12:00:00';
        $createdAt = '2023-08-31 11:00:00';

        $result = TeHelper::willExpireAt($dueTime, $createdAt);

        $this->assertEquals('2023-08-31 12:00:00', $result);
    }

    public function testWillExpireAtWithin24Hours()
    {
        $dueTime = '2023-08-31 12:00:00';
        $createdAt = '2023-08-31 10:00:00';
        $result = TeHelper::willExpireAt($dueTime, $createdAt);

        $this->assertEquals('2023-08-31 11:30:00', $result);
    }

    public function testWillExpireAtWithin72Hours()
    {
        $dueTime = '2023-08-31 12:00:00';
        $createdAt = '2023-08-29 12:00:00';

        $result = TeHelper::willExpireAt($dueTime, $createdAt);

        $this->assertEquals('2023-08-31 04:00:00', $result);
    }

    public function testWillExpireAtAfter72Hours()
    {
        $dueTime = '2023-08-31 12:00:00';
        $createdAt = '2023-08-28 12:00:00';
        $result = TeHelper::willExpireAt($dueTime, $createdAt);

        $this->assertEquals('2023-08-29 12:00:00', $result);
    }
}
