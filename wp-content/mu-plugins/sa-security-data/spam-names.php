<?php
/**
 * Bundled spam name list.
 *
 * Matched as an exact, lowercased, whole-field comparison against the billing
 * first and last name. Never as a substring: "test" as a substring would block
 * a customer named Testa or Contestabile.
 *
 * WHAT MAY GO IN THIS FILE
 *
 * Only strings that are not names in any language: keyboard mash, form-filler
 * placeholders, and literal bot output. The bar is that a human being could not
 * plausibly be called this.
 *
 * WHAT MAY NEVER GO IN THIS FILE
 *
 * Real given names or surnames, however often they turn up in spam. Blocking a
 * name because fraudsters reuse it means rejecting every genuine customer who
 * happens to have it, and a list of "spam names" drawn from any one language or
 * country is discrimination with extra steps. The IP, email and country rules
 * exist for targeting; this list is only for input that is obviously not a name.
 *
 * This list is deliberately short. A short list that never blocks a real
 * customer is worth more than a long one that sometimes does.
 *
 * @package checkout-guard
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return array(
	// Placeholders left in by form fillers and test harnesses.
	'test'          => true,
	'testing'       => true,
	'testtest'      => true,
	'test test'     => true,
	'firstname'     => true,
	'lastname'      => true,
	'first name'    => true,
	'last name'     => true,
	'yourname'      => true,
	'your name'     => true,
	'fullname'      => true,
	'full name'     => true,
	'name'          => true,
	'example'       => true,
	'dummy'         => true,
	'placeholder'   => true,
	'none'          => true,
	'n/a'           => true,
	'null'          => true,
	'undefined'     => true,
	'unknown'       => true,
	'anonymous'     => true,
	'nobody'        => true,
	'noname'        => true,
	'no name'       => true,

	// Keyboard mash.
	'asdf'          => true,
	'asdfg'         => true,
	'asdfgh'        => true,
	'asdfasdf'      => true,
	'asd'           => true,
	'qwe'           => true,
	'qwer'          => true,
	'qwert'         => true,
	'qwerty'        => true,
	'qwertyuiop'    => true,
	'zxc'           => true,
	'zxcv'          => true,
	'zxcvbn'        => true,
	'aaa'           => true,
	'aaaa'          => true,
	'aaaaa'         => true,
	'abc'           => true,
	'abcd'          => true,
	'abcde'         => true,
	'xxx'           => true,
	'xxxx'          => true,
	'ffff'          => true,
	'sdfsdf'        => true,
	'fdsa'          => true,
	'jkl'           => true,
	'hjkl'          => true,
	'123'           => true,
	'1234'          => true,
	'12345'         => true,
	'123456'        => true,

	// Literal bot and template output.
	'admin'         => true,
	'administrator' => true,
	'user'          => true,
	'customer'      => true,
	'buyer'         => true,
	'bot'           => true,
	'spam'          => true,
	'spammer'       => true,
	'fake'          => true,
	'fakename'      => true,
	'lorem'         => true,
	'ipsum'         => true,
	'lorem ipsum'   => true,
	'john doe'      => true,
	'jane doe'      => true,
	'foobar'        => true,
	// Not listed, and deliberately. Every one of these is a real surname:
	// 'tester', 'guest', 'foo', 'sample', 'bar', 'baz', 'na'. 'foobar' is
	// safe because no one is called that.
);
