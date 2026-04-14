<?php

namespace Tests\Unit\Services;

use App\Services\Auth\PasswordValidator;
use Tests\TestCase;

class PasswordValidatorTest extends TestCase
{
    private PasswordValidator $validator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->validator = new PasswordValidator(minLength: 12, historyCount: 5);
    }

    public function test_valid_password_passes_complexity(): void
    {
        $errors = $this->validator->validateComplexity('SecurePass1!');
        $this->assertEmpty($errors);
    }

    public function test_too_short_password_fails(): void
    {
        $errors = $this->validator->validateComplexity('Short1!');
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('12 characters', $errors[0]);
    }

    public function test_missing_uppercase_fails(): void
    {
        $errors = $this->validator->validateComplexity('alllowercase1!');
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('uppercase', $errors[0]);
    }

    public function test_missing_digit_fails(): void
    {
        $errors = $this->validator->validateComplexity('NoDigitsHere!A');
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('digit', implode(' ', $errors));
    }

    public function test_missing_special_character_fails(): void
    {
        $errors = $this->validator->validateComplexity('NoSpecialChar1A');
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('special', implode(' ', $errors));
    }

    public function test_missing_lowercase_fails(): void
    {
        $errors = $this->validator->validateComplexity('ALLUPPERCASE1!');
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('lowercase', implode(' ', $errors));
    }

    public function test_all_complexity_rules_can_fail_simultaneously(): void
    {
        $errors = $this->validator->validateComplexity('a');
        $this->assertCount(4, $errors); // short, no uppercase, no digit, no special
    }
}
