<?php

namespace Personnummer\Tests;

use DateTime;
use Jchook\AssertThrows\AssertThrows;
use Personnummer\Personnummer;
use Personnummer\PersonnummerException;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use TypeError;

class PersonnummerTest extends TestCase
{
    use AssertThrows;
    use AssertError;

    private static array $testdataList;

    private static array $testdataStructured;

    private static array $availableListFormats = [
        'integer',
        'long_format',
        'short_format',
        'separated_format',
        'separated_long',
    ];

    public static function setUpBeforeClass(): void
    {
        self::$testdataList       = json_decode(file_get_contents('https://raw.githubusercontent.com/personnummer/meta/master/testdata/list.json'), true, 512, JSON_THROW_ON_ERROR); // phpcs:ignore
        self::$testdataStructured = json_decode(file_get_contents('https://raw.githubusercontent.com/personnummer/meta/master/testdata/structured.json'), true, 512, JSON_THROW_ON_ERROR); // phpcs:ignore
    }

    public function testParse(): void
    {
        $this->assertSame(Personnummer::class, get_class(Personnummer::parse('1212121212')));
        $this->assertEquals(new Personnummer('1212121212'), Personnummer::parse('1212121212'));
    }

    public function testOptions(): void
    {
        new Personnummer('1212621211');

        $this->assertThrows(PersonnummerException::class, function () {
            new Personnummer('1212621211', ['allowCoordinationNumber' => false]);
        });
        $this->assertError(function () {
            new Personnummer('1212121212', ['invalidOption' => true]);
        }, E_USER_WARNING);
    }

    public function testPersonnummerData(): void
    {
        foreach (self::$testdataList as $testdata) {
            foreach (self::$availableListFormats as $format) {
                $this->assertSame(
                    $testdata['valid'],
                    Personnummer::valid($testdata[$format]),
                    sprintf(
                        '%s (%s) should be %s',
                        $testdata[$format],
                        $format,
                        $testdata['valid'] ? 'valid' : 'not valid'
                    )
                );
            }
        }

        foreach (self::$testdataStructured as $ssnType => $testdataInputs) {
            foreach ($testdataInputs as $testdata) {
                foreach ($testdata as $valid => $ssns) {
                    foreach ($ssns as $ssn) {
                        $this->assertSame(
                            $valid === 'valid' && $ssnType === 'ssn',
                            Personnummer::valid($ssn, ['allowCoordinationNumber' => false]),
                            sprintf(
                                '%s should be %s',
                                $ssn,
                                ($valid === 'valid' && $ssnType === 'ssn' ? 'valid' : 'not valid')
                            )
                        );
                    }
                }
            }
        }
    }

    public function testFormat(): void
    {
        foreach (self::$testdataList as $testdata) {
            if ($testdata['valid']) {
                foreach (self::$availableListFormats as $format) {
                    if ($format === 'short_format' && str_contains($testdata['separated_format'], '+')) {
                        continue;
                    }

                    $this->assertSame($testdata['separated_format'], (new Personnummer($testdata[$format]))->format());

                    $this->assertSame($testdata['long_format'], Personnummer::parse($testdata[$format])->format(true));
                }
            }
        }
    }

    public function testThrowsErrorOnInvalid(): void
    {
        foreach (self::$testdataList as $testdata) {
            if (!$testdata['valid']) {
                foreach (self::$availableListFormats as $format) {
                    $this->assertThrows(PersonnummerException::class, function () use ($testdata, $format) {
                        Personnummer::parse($testdata[$format]);
                    });
                    $this->assertFalse(Personnummer::valid($testdata[$format]));
                }
            }

            if ($testdata['type'] === 'con') {
                foreach (self::$availableListFormats as $format) {
                    $this->assertThrows(PersonnummerException::class, function () use ($testdata, $format) {
                        Personnummer::parse($testdata[$format], ['allowCoordinationNumber' => false]);
                    });
                    $this->assertFalse(Personnummer::valid($testdata[$format], ['allowCoordinationNumber' => false]));
                }
            }
        }

        for ($i = 0; $i < 2; $i++) {
            $this->assertThrows(PersonnummerException::class, function () use ($i) {
                new Personnummer(boolval($i));
            });

            $this->assertFalse(Personnummer::valid(boolval($i)));
        }

        foreach ([null, []] as $invalidType) {
            $this->assertThrows(TypeError::class, function () use ($invalidType) {
                new Personnummer($invalidType);
            });
            $this->assertThrows(TypeError::class, function () use ($invalidType) {
                Personnummer::valid($invalidType);
            });
        }
    }

    public function testAge(): void
    {
        foreach (self::$testdataList as $testdata) {
            if ($testdata['valid']) {
                $birthdate = substr($testdata['separated_long'], 0, 8);
                if ($testdata['type'] === 'con') {
                    $birthdate = substr($birthdate, 0, 6) .
                        str_pad((int)substr($birthdate, -2) - 60, 2, "0", STR_PAD_LEFT);
                }

                $expected = (int)(new DateTime($birthdate))->diff(new DateTime())->format('%y');

                foreach (self::$availableListFormats as $format) {
                    if ($format === 'short_format' && str_contains($testdata['separated_format'], '+')) {
                        continue;
                    }

                    $this->assertSame($expected, Personnummer::parse($testdata[$format])->getAge());
                }
            }
        }
    }

    public function testAgeFuture(): void
    {
        $clock = new TestClock(new \DateTimeImmutable("2020-01-01 12:00"));

        $tests = [
            ['ssn' => 203501010718, 'age' => -14],
            ['ssn' => 204501018131, 'age' => -24],
            ['ssn' => 213501014330, 'age' => -114]
         ];

        foreach ($tests as $test) {
            $ssn = Personnummer::parse($test['ssn'], ['clock' => $clock]);
            self::assertSame($test['age'], $ssn->getAge());
        }
    }

    public function testAgeOnBirthday(): void
    {
        $date     = (new DateTime())->modify('-30 years midnight');
        $expected = (int)$date->diff(new DateTime())->format('%y');

        $ssn = $date->format('Ymd') . '999';

        // Access private luhn method
        $reflector = new ReflectionClass(Personnummer::class);
        $method    = $reflector->getMethod('luhn');
        $method->setAccessible(true);
        $ssn .= $method->invoke(null, substr($ssn, 2));

        $this->assertSame($expected, Personnummer::parse($ssn)->getAge());
    }

    public function testSex(): void
    {
        foreach (self::$testdataList as $testdata) {
            if ($testdata['valid']) {
                foreach (self::$availableListFormats as $format) {
                    $this->assertSame($testdata['isMale'], Personnummer::parse($testdata[$format])->isMale());
                    $this->assertSame($testdata['isFemale'], Personnummer::parse($testdata[$format])->isFemale());
                }
            }
        }
    }

    public function testProperties(): void
    {
        // Parts, as position and length
        $separatedLongParts = [
            'century'  => [0, 2],
            'year'     => [2, 2],
            'fullYear' => [0, 4],
            'month'    => [4, 2],
            'day'      => [6, 2],
            'sep'      => [8, 1],
            'num'      => [9, 3],
            'check'    => [12, 1],
        ];
        foreach (self::$testdataList as $testdata) {
            if ($testdata['valid']) {
                foreach ($separatedLongParts as $partName => $pos) {
                    $expected = call_user_func_array('substr', array_merge([$testdata['separated_long']], $pos));
                    $this->assertSame($expected, Personnummer::parse($testdata['separated_format'])->$partName);
                    $this->assertSame($expected, Personnummer::parse($testdata['separated_format'])->__get($partName));
                    $this->assertTrue(isset(Personnummer::parse($testdata['separated_format'])->$partName));
                }
            }
        }
    }

    public function testMissingProperties(): void
    {
        $this->assertError(function () {
            Personnummer::parse('1212121212')->missingProperty;
        }, E_USER_NOTICE);
        $this->assertFalse(isset(Personnummer::parse('121212-1212')->missingProperty));
    }

    public function testIsNotInterim(): void
    {
        foreach (self::$testdataList as $testdata) {
            if ($testdata['valid']) {
                $this->assertFalse(Personnummer::parse($testdata['separated_format'])->isInterimNumber());
            }
        }
    }

    public function testDate(): void
    {
        foreach (self::$testdataList as $testdata) {
            if (!$testdata['valid']) {
                continue;
            }

            $val = $testdata['long_format'];
            if ($testdata['type'] === 'con') {
                // If it's a coordination number, we aught to remove 60 from the "real day" to make sure
                // we get the correct date.
                $val = substr($testdata['long_format'], 0, 6); // YYMM
                $val .= (int)substr($testdata['long_format'], 6, 2) - 60; // DD
            }

            $pn   = Personnummer::parse($testdata['separated_format']);
            $date = DateTime::createFromFormat('Ymd', substr($val, 0, 8)); // Only want YYYYMMDD

            self::assertSame(
                $pn->getDate()->format("Ymd"),
                $date->format('Ymd')
            );
        }
    }
}
