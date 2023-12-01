<?php

declare(strict_types=1);
include_once __DIR__ . '/stubs/Validator.php';
class AlarmierungValidationTest extends TestCaseSymconValidation
{
    public function testValidateAlarmierung(): void
    {
        $this->validateLibrary(__DIR__ . '/..');
    }
    public function testValidateAlertingModule(): void
    {
        $this->validateModule(__DIR__ . '/../Alerting');
    }
}