<?php

declare(strict_types=1);

namespace IamAdty\Empira\Tests;

use IamAdty\Empira\Validation\ErrorBag;
use IamAdty\Empira\Validation\Rule;
use IamAdty\Empira\Validation\ValidationException;
use IamAdty\Empira\Validation\Validator;
use PHPUnit\Framework\TestCase;

final class ValidationTest extends TestCase
{
    // ------------------------------------------------------------------ required

    public function testItAcceptsAFieldThatIsPresentAndFilled(): void
    {
        $errors = Validator::make(['name' => 'Ada'], ['name' => 'required']);

        $this->assertFalse($errors->any());
    }

    public function testItRejectsAnEmptyRequiredField(): void
    {
        $errors = Validator::make(['name' => ''], ['name' => 'required']);

        $this->assertTrue($errors->has('name'));
        $this->assertSame(['The name field is required.'], $errors->messages('name'));
    }

    public function testItRejectsARequiredFieldThatIsMissingEntirely(): void
    {
        $errors = Validator::make([], ['name' => 'required']);

        $this->assertSame('The name field is required.', $errors->first('name'));
    }

    public function testItRejectsRequiredFieldsThatAreNullOrEmptyLists(): void
    {
        $this->assertTrue(Validator::make(['a' => null], ['a' => 'required'])->any());
        $this->assertTrue(Validator::make(['a' => ''], ['a' => 'required'])->any());
        $this->assertTrue(Validator::make(['a' => []], ['a' => 'required'])->any());
    }

    public function testRequiredAcceptsZeroAndFalseAsValues(): void
    {
        $this->assertFalse(Validator::make(['a' => 0], ['a' => 'required'])->any());
        $this->assertFalse(Validator::make(['a' => false], ['a' => 'required'])->any());
    }

    public function testRequiredFailsOnceAndDoesNotAlsoComplainAboutType(): void
    {
        $errors = Validator::make([], ['age' => 'required|integer']);

        $this->assertCount(1, $errors->messages('age'));
        $this->assertSame('The age field is required.', $errors->first('age'));
    }

    // -------------------------------------------------------------------- types

    public function testItChecksStrings(): void
    {
        $this->assertFalse(Validator::make(['name' => 'Ada'], ['name' => 'string'])->any());
        $this->assertSame(
            ['The name field must be a string.'],
            Validator::make(['name' => 42], ['name' => 'string'])->messages('name')
        );
    }

    public function testItAcceptsANumericStringForInteger(): void
    {
        $this->assertFalse(Validator::make(['age' => '12'], ['age' => 'integer'])->any());
        $this->assertFalse(Validator::make(['age' => 12], ['age' => 'integer'])->any());
        $this->assertFalse(Validator::make(['age' => '-3'], ['age' => 'integer'])->any());
        $this->assertFalse(Validator::make(['age' => 12.0], ['age' => 'integer'])->any());
    }

    public function testItRejectsValuesThatOnlyLookLikeIntegers(): void
    {
        $this->assertTrue(Validator::make(['age' => '12.5'], ['age' => 'integer'])->any());
        $this->assertTrue(Validator::make(['age' => '12px'], ['age' => 'integer'])->any());
        $this->assertTrue(Validator::make(['age' => ''], ['age' => 'integer'])->any());
        $this->assertTrue(Validator::make(['age' => true], ['age' => 'integer'])->any());
        $this->assertTrue(Validator::make(['age' => 1.5], ['age' => 'integer'])->any());
    }

    public function testIntIsAnAliasForInteger(): void
    {
        $this->assertSame(Rule::Integer, Rule::of('int'));
        $this->assertFalse(Validator::make(['age' => 3], ['age' => 'int'])->any());
        $this->assertTrue(Validator::make(['age' => 'three'], ['age' => 'int'])->any());
    }

    public function testItChecksNumerics(): void
    {
        $this->assertFalse(Validator::make(['rate' => '1.5'], ['rate' => 'numeric'])->any());
        $this->assertFalse(Validator::make(['rate' => 2], ['rate' => 'numeric'])->any());
        $this->assertSame(
            ['The rate field must be a number.'],
            Validator::make(['rate' => 'lots'], ['rate' => 'numeric'])->messages('rate')
        );
    }

    public function testItChecksBooleans(): void
    {
        $this->assertFalse(Validator::make(['on' => true], ['on' => 'boolean'])->any());
        $this->assertFalse(Validator::make(['on' => '1'], ['on' => 'boolean'])->any());
        $this->assertFalse(Validator::make(['on' => 'off'], ['on' => 'boolean'])->any());

        $errors = Validator::make(['on' => 'maybe'], ['on' => 'boolean']);

        $this->assertSame('The on field must be true or false.', $errors->first('on'));
    }

    public function testBoolIsAnAliasForBoolean(): void
    {
        $this->assertSame(Rule::Boolean, Rule::of('bool'));
        $this->assertFalse(Validator::make(['on' => false], ['on' => 'bool'])->any());
        $this->assertTrue(Validator::make(['on' => 'perhaps'], ['on' => 'bool'])->any());
    }

    public function testItChecksArrays(): void
    {
        $this->assertFalse(Validator::make(['tags' => ['a', 'b']], ['tags' => 'array'])->any());
        $this->assertSame(
            ['The tags field must be an array.'],
            Validator::make(['tags' => 'a,b'], ['tags' => 'array'])->messages('tags')
        );
    }

    public function testItChecksEmails(): void
    {
        $this->assertFalse(Validator::make(['email' => 'ada@example.com'], ['email' => 'email'])->any());
        $this->assertSame(
            ['The email field must be a valid email address.'],
            Validator::make(['email' => 'ada@example'], ['email' => 'email'])->messages('email')
        );
    }

    // ------------------------------------------------------------ min and max

    public function testMinMeasuresNumbers(): void
    {
        $this->assertFalse(Validator::make(['age' => 18], ['age' => 'min:18'])->any());
        $this->assertFalse(Validator::make(['age' => '19'], ['age' => 'min:18'])->any());
        $this->assertSame(
            ['The age field must be at least 18.'],
            Validator::make(['age' => 17], ['age' => 'min:18'])->messages('age')
        );
    }

    public function testMaxMeasuresNumbers(): void
    {
        $this->assertFalse(Validator::make(['age' => 18], ['age' => 'max:18'])->any());
        $this->assertSame(
            ['The age field may not be greater than 18.'],
            Validator::make(['age' => '19'], ['age' => 'max:18'])->messages('age')
        );
    }

    public function testMinAndMaxMeasureStringLength(): void
    {
        $this->assertFalse(Validator::make(['code' => 'abc'], ['code' => 'min:3|max:5'])->any());
        $this->assertTrue(Validator::make(['code' => 'ab'], ['code' => 'min:3'])->any());
        $this->assertTrue(Validator::make(['code' => 'abcdef'], ['code' => 'max:5'])->any());
    }

    public function testMinAndMaxCountListItems(): void
    {
        $this->assertFalse(Validator::make(['tags' => ['a', 'b']], ['tags' => 'array|min:2|max:3'])->any());
        $this->assertTrue(Validator::make(['tags' => ['a']], ['tags' => 'min:2'])->any());
        $this->assertTrue(Validator::make(['tags' => ['a', 'b', 'c', 'd']], ['tags' => 'max:3'])->any());
    }

    public function testMinAndMaxMeasureANumericStringAsANumber(): void
    {
        $this->assertFalse(Validator::make(['age' => '19'], ['age' => 'min:18|max:65'])->any());
        $this->assertTrue(Validator::make(['age' => '17'], ['age' => 'min:18'])->any());
        $this->assertTrue(Validator::make(['age' => '66'], ['age' => 'max:65'])->any());
    }

    public function testMinAndMaxMeasureOtherStringsByLength(): void
    {
        $this->assertFalse(Validator::make(['code' => 'abcd'], ['code' => 'min:2|max:5'])->any());
        $this->assertTrue(Validator::make(['code' => 'a'], ['code' => 'min:2'])->any());
        $this->assertTrue(Validator::make(['code' => 'abcdef'], ['code' => 'max:5'])->any());
    }

    public function testStringLengthCountsCharactersNotBytes(): void
    {
        $this->assertFalse(Validator::make(['bio' => 'héllo'], ['bio' => 'max:5'])->any());
    }

    public function testAValueMinCannotMeasureFailsTheRule(): void
    {
        $errors = Validator::make(['thing' => null], ['thing' => 'min:1']);

        $this->assertSame('The thing field must be at least 1.', $errors->first('thing'));
    }

    // ----------------------------------------------------- in, regex, cross-field

    public function testItChecksValuesAgainstASet(): void
    {
        $this->assertFalse(Validator::make(['colour' => 'red'], ['colour' => 'in:red,green,blue'])->any());
        $this->assertFalse(Validator::make(['level' => 2], ['level' => 'in:1,2,3'])->any());

        $errors = Validator::make(['colour' => 'purple'], ['colour' => 'in:red,green,blue']);

        $this->assertSame('The colour field must be one of: red, green, blue.', $errors->first('colour'));
    }

    public function testItChecksValuesAgainstAPattern(): void
    {
        $this->assertFalse(Validator::make(['slug' => 'empira-2'], ['slug' => 'regex:/^[a-z0-9-]+$/'])->any());
        $this->assertFalse(Validator::make(['slug' => 'EMPIRA'], ['slug' => 'regex:/^[a-z]+$/i'])->any());
        $this->assertSame(
            ['The slug field format is invalid.'],
            Validator::make(['slug' => 'not a slug'], ['slug' => 'regex:/^[a-z0-9-]+$/'])->messages('slug')
        );
    }

    public function testItRejectsAPatternThatWillNotCompile(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('compile');

        Validator::make(['slug' => 'x'], ['slug' => 'regex:/[unclosed/']);
    }

    public function testItChecksAConfirmedFieldAgainstItsConfirmation(): void
    {
        $data = ['password' => 'secret', 'password_confirmation' => 'secret'];

        $this->assertFalse(Validator::make($data, ['password' => 'confirmed'])->any());

        $errors = Validator::make(['password' => 'secret', 'password_confirmation' => 'other'], ['password' => 'confirmed']);

        $this->assertSame('The password confirmation does not match.', $errors->first('password'));
    }

    public function testAConfirmedFieldFailsWhenTheConfirmationIsMissing(): void
    {
        $errors = Validator::make(['password' => 'secret'], ['password' => 'confirmed']);

        $this->assertTrue($errors->has('password'));
    }

    public function testItChecksAFieldAgainstAnotherField(): void
    {
        $this->assertFalse(Validator::make(
            ['password' => 'secret', 'password_repeat' => 'secret'],
            ['password_repeat' => 'same:password']
        )->any());

        $errors = Validator::make(
            ['password' => 'secret', 'password_repeat' => 'typo'],
            ['password_repeat' => 'same:password']
        );

        $this->assertSame('The password_repeat field must match the password field.', $errors->first('password_repeat'));
    }

    public function testSameReadsTheOtherFieldThroughDotNotation(): void
    {
        $data = ['billing' => ['city' => 'Bath'], 'shipping' => ['city' => 'Bath']];

        $this->assertFalse(Validator::make($data, ['shipping.city' => 'same:billing.city'])->any());
        $this->assertTrue(Validator::make(
            ['billing' => ['city' => 'Bath'], 'shipping' => ['city' => 'York']],
            ['shipping.city' => 'same:billing.city']
        )->any());
    }

    // ----------------------------------------------------------------- nullable

    public function testNullableSkipsTheOtherRulesForANullValue(): void
    {
        $errors = Validator::make(['nickname' => null], ['nickname' => 'nullable|integer|max:3']);

        $this->assertFalse($errors->any());
    }

    public function testNullableStillChecksAValueThatIsThere(): void
    {
        $this->assertFalse(Validator::make(['nickname' => 'Ada'], ['nickname' => 'nullable|string'])->any());
        $this->assertTrue(Validator::make(['nickname' => []], ['nickname' => 'nullable|string'])->any());
    }

    public function testNullableDoesNotExcuseAnEmptyString(): void
    {
        $errors = Validator::make(['nickname' => ''], ['nickname' => 'nullable|integer']);

        $this->assertTrue($errors->any());
    }

    public function testRequiredIsCheckedBeforeNullable(): void
    {
        $errors = Validator::make(['nickname' => null], ['nickname' => 'nullable|required']);

        $this->assertSame(['The nickname field is required.'], $errors->messages('nickname'));
    }

    // ---------------------------------------------------------------- dot paths

    public function testItValidatesNestedFieldsByDot(): void
    {
        $data = ['user' => ['name' => 'Ada', 'address' => ['city' => 'Bath']]];

        $errors = Validator::make($data, [
            'user.name' => 'required|string',
            'user.address.city' => 'required|string',
        ]);

        $this->assertFalse($errors->any());
    }

    public function testAMissingNestedBranchIsAMissingField(): void
    {
        $errors = Validator::make(['user' => ['name' => 'Ada']], ['user.address.city' => 'required']);

        $this->assertSame(['The user.address.city field is required.'], $errors->messages('user.address.city'));
    }

    public function testAStringWhereAnArrayWasExpectedIsNotTraversed(): void
    {
        $errors = Validator::make(['user' => 'Ada'], ['user.name' => 'required']);

        $this->assertTrue($errors->has('user.name'));
    }

    public function testAnArrayFieldIsCheckedAsAWholeValue(): void
    {
        $data = ['tags' => ['red', 'green', 'blue']];

        $this->assertFalse(Validator::make($data, ['tags' => 'required|array|min:2|max:5'])->any());
        $this->assertTrue(Validator::make(['tags' => 'red'], ['tags' => 'required|array'])->any());
    }

    // ------------------------------------------------------------- rule syntax

    public function testAFieldCanTakeAnArrayOfRules(): void
    {
        $errors = Validator::make(['email' => 'nope'], ['email' => ['required', 'email', 'max:100']]);

        $this->assertSame('The email field must be a valid email address.', $errors->first('email'));
        $this->assertCount(1, $errors->messages('email'));
    }

    public function testAnArrayOfRulesMayAlsoBePipeSeparated(): void
    {
        $errors = Validator::make(['email' => ''], ['email' => ['required|string', 'email']]);

        $this->assertSame('The email field is required.', $errors->first('email'));
    }

    public function testOneFieldCanFailSeveralRules(): void
    {
        $errors = Validator::make(['bio' => str_repeat('a', 500)], ['bio' => 'integer|max:10']);

        $this->assertCount(2, $errors->messages('bio'));
        $this->assertSame('The bio field must be an integer.', $errors->first('bio'));
    }

    public function testAnAbsentFieldOnlyBothersRequired(): void
    {
        $this->assertFalse(Validator::make([], ['nickname' => 'string|email'])->any());
    }

    public function testItThrowsOnARuleItDoesNotKnow(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown validation rule "requried" on field "name".');

        Validator::make(['name' => 'Ada'], ['name' => 'requried']);
    }

    public function testItThrowsOnAMisspeltRuleEvenWhenTheValueWouldSkipIt(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('requried');

        Validator::make(['name' => null], ['name' => 'nullable|requried']);
    }

    public function testItThrowsWhenARuleIsMissingItsParameter(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('needs a parameter');

        Validator::make(['bio' => 'x'], ['bio' => 'max']);
    }

    public function testItThrowsWhenInIsGivenNoChoices(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Validator::make(['colour' => 'red'], ['colour' => 'in:']);
    }

    public function testItThrowsWhenMinIsGivenSomethingThatIsNotANumber(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('needs a number');

        Validator::make(['bio' => 'x'], ['bio' => 'max:long']);
    }

    public function testRuleNamesAreCaseInsensitive(): void
    {
        $this->assertFalse(Validator::make(['name' => 'Ada'], ['name' => 'Required|String'])->any());
        $this->assertSame(Rule::Integer, Rule::of(' INTEGER '));
        $this->assertNull(Rule::of('maxmum'));
    }

    // -------------------------------------------------------------- immutability

    public function testValidationLeavesTheDataAlone(): void
    {
        $data = ['age' => '12', 'name' => 'Ada'];

        Validator::make($data, ['age' => 'integer', 'name' => 'required|string']);

        $this->assertSame(['age' => '12', 'name' => 'Ada'], $data);
    }

    public function testAValidatorCanBeReusedAcrossRuleSets(): void
    {
        $validator = new Validator(['email' => 'ada@example.com', 'name' => '']);

        $this->assertFalse($validator->validate(['email' => 'email'])->any());
        $this->assertTrue($validator->validate(['name' => 'required'])->any());
    }

    public function testAnEmptyRuleSetProducesNoErrors(): void
    {
        $this->assertFalse((new Validator(['a' => 1]))->validate([])->any());
    }

    // ------------------------------------------------------------------ ErrorBag

    public function testErrorBagCollectsMessagesPerField(): void
    {
        $errors = new ErrorBag();

        $this->assertFalse($errors->any());
        $this->assertSame(0, $errors->count());

        $errors->add('email', 'The email field is required.');
        $errors->add('email', 'The email field must be a valid email address.');
        $errors->add('name', 'The name field is required.');

        $this->assertTrue($errors->any());
        $this->assertTrue($errors->has('email'));
        $this->assertFalse($errors->has('password'));
        $this->assertSame(2, $errors->count());
        $this->assertSame(2, count($errors));
    }

    public function testErrorBagReturnsMessagesListsAndFirsts(): void
    {
        $errors = new ErrorBag();
        $errors->add('email', 'first');
        $errors->add('email', 'second');

        $this->assertSame(['first', 'second'], $errors->messages('email'));
        $this->assertSame([], $errors->messages('nothing'));
        $this->assertSame('first', $errors->first('email'));
        $this->assertNull($errors->first('nothing'));
        $this->assertSame(['email' => ['first', 'second']], $errors->all());
    }

    public function testErrorBagIsJsonSerializable(): void
    {
        $errors = Validator::make(['email' => ''], ['email' => 'required|email']);

        $this->assertInstanceOf(\JsonSerializable::class, $errors);
        $this->assertSame($errors->all(), $errors->jsonSerialize());
        $this->assertSame(
            '{"email":["The email field is required."]}',
            json_encode($errors, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
        );
    }

    // -------------------------------------------------------- ValidationException

    public function testValidationExceptionCarriesItsErrors(): void
    {
        $errors = Validator::make(['email' => 'nope'], ['email' => 'required|email']);
        $exception = new ValidationException($errors);

        $this->assertSame($errors, $exception->errors());
        $this->assertSame(422, $exception->status);
        $this->assertSame('Validation failed for: email.', $exception->getMessage());
        $this->assertInstanceOf(\IamAdty\Empira\Exception::class, $exception);
        $this->assertInstanceOf(\RuntimeException::class, $exception);
    }

    public function testValidationExceptionTakesAMessageAndAStatus(): void
    {
        $exception = new ValidationException(new ErrorBag(), 409, 'That todo was archived.');

        $this->assertSame(409, $exception->status);
        $this->assertSame('That todo was archived.', $exception->getMessage());
        $this->assertSame([], $exception->errors()->all());
    }

    public function testAnEmptyBagStillSummarises(): void
    {
        $this->assertSame('Validation failed.', (new ValidationException(new ErrorBag()))->getMessage());
    }
}