<?php


use PHPUnit\Framework\TestCase;

class UserTest extends TestCase
{
    public function testCreateOrUpdate()
    {
        $requestData = [
            'role' => 'customer',
            'name' => 'Test User',
        ];

        $userRepository = new UserRepository();

        $result = $userRepository->createOrUpdate(null, $requestData);

        $this->assertNotFalse($result);
    }
}
