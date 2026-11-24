<?php
declare(strict_types=1);

namespace Kristos80\Mule\Json;

use Kristos80\Mule\Failure;

/**
 * Decoded JSON, narrowed to types once.
 *
 * **Everything untyped that mule reads comes in through here** — `mule.json`
 * and the committed store — and leaves as a `string` or a `list<string>`.
 * Narrowing the same value in several places is several chances to narrow it
 * differently, and it is also what makes a static analyser's demand for a check
 * multiply into a test per demand. There is one of each here instead.
 *
 * **The decode keeps objects as objects**, and their property names become the
 * keys here in one place, {@see self::propertiesOf()}. Associative decoding
 * would hand back `int|string` keys and want a `(string)` cast in every place
 * that reads one — and a cast PHP performs anyway is dead code that mutation
 * testing reports as a survivor nothing can kill, which is how the first
 * suppression gets written. Interpolating a name that is already a string is
 * the same conversion with no mutant and no cast to explain away.
 */
final readonly class Decoded {

	/**
	 * @param array<string, mixed> $values
	 * @param string $subject What is being read, for the message.
	 */
	private function __construct(
		private array $values,
		private string $subject,
	) {}

	/**
	 * One JSON object, decoded.
	 *
	 * Anything that is not an object — unparseable bytes, a list, a bare value
	 * — fails here rather than reading as empty: an absent file is the caller's
	 * business, and a file that *is* there and says nothing mule understands is
	 * damage worth stopping for.
	 *
	 * @param string $json
	 * @param string $subject
	 * @return self
	 */
	public static function of(string $json, string $subject): self {
		$decoded = json_decode($json);

		if(!is_object($decoded)) {
			throw new Failure("Cannot read {$subject}: mule needs it to be a JSON object.");
		}

		return new self(self::propertiesOf($decoded), $subject);
	}

	/**
	 * A key that must be there, as a non-empty string.
	 *
	 * @param string $key
	 * @return string
	 */
	public function string(string $key): string {
		$value = $this->values[$key] ?? NULL;

		if(!is_string($value) || $value === "") {
			throw new Failure("The {$key} in {$this->subject} must be a non-empty string.");
		}

		return $value;
	}

	/**
	 * A key that need not be there, as a non-empty string.
	 *
	 * @param string $key
	 * @param string $fallback
	 * @return string
	 */
	public function stringOr(string $key, string $fallback): string {
		return array_key_exists($key, $this->values) ? $this->string($key) : $fallback;
	}

	/**
	 * A key that need not be there, as a list of strings; absent reads as
	 * empty.
	 *
	 * @param string $key
	 * @return list<string>
	 */
	public function strings(string $key): array {
		$value = $this->values[$key] ?? [];
		$items = [];

		if(!is_array($value)) {
			throw new Failure("The {$key} in {$this->subject} must be a list of strings.");
		}

		foreach($value as $item) {
			if(!is_string($item)) {
				throw new Failure("The {$key} in {$this->subject} must be a list of strings.");
			}

			$items[] = $item;
		}

		return $items;
	}

	/**
	 * A key that must be there, as a whole number.
	 *
	 * @param string $key
	 * @return int
	 */
	public function number(string $key): int {
		$value = $this->values[$key] ?? NULL;

		if(!is_int($value)) {
			throw new Failure("The {$key} in {$this->subject} must be a whole number.");
		}

		return $value;
	}

	/**
	 * A key that need not be there, as a list of objects; absent reads as
	 * empty.
	 *
	 * @param string $key
	 * @return list<self>
	 */
	public function objects(string $key): array {
		$value = $this->values[$key] ?? [];
		$objects = [];

		if(!is_array($value)) {
			throw new Failure("The {$key} in {$this->subject} must be a list of objects.");
		}

		foreach($value as $item) {
			if(!is_object($item)) {
				throw new Failure("The {$key} in {$this->subject} must be a list of objects.");
			}

			$objects[] = new self(self::propertiesOf($item), "{$this->subject} {$key}");
		}

		return $objects;
	}

	/**
	 * A nested object, or an empty one where the key is absent.
	 *
	 * **Absent reads as empty on purpose.** The store is written by mule and
	 * read by a later mule, which may want a section the writer had never heard
	 * of; a missing section means "nothing recorded under that heading yet",
	 * and the run re-proves what it cannot find. A section that is there and is
	 * not an object is damage, and says so.
	 *
	 * @param string $key
	 * @return self
	 */
	public function object(string $key): self {
		$value = $this->values[$key] ?? NULL;
		$subject = "{$this->subject} {$key}";

		if($value === NULL) {
			return new self([], $subject);
		}

		if(!is_object($value)) {
			throw new Failure("The {$key} in {$this->subject} must be an object.");
		}

		return new self(self::propertiesOf($value), $subject);
	}

	/**
	 * Every key of this object, each of whose values is itself an object. This
	 * is the shape of the store: a path to what was recorded for it.
	 *
	 * @return array<string, self>
	 */
	public function entries(): array {
		$entries = [];

		foreach($this->values as $key => $value) {
			if(!is_object($value)) {
				throw new Failure("The entry for {$key} in {$this->subject} is malformed.");
			}

			$entries[$key] = new self(self::propertiesOf($value), "{$this->subject} entry for {$key}");
		}

		return $entries;
	}

	/**
	 * An object's properties, keyed by name.
	 *
	 * @param object $values
	 * @return array<string, mixed>
	 */
	private static function propertiesOf(object $values): array {
		$properties = [];

		foreach(get_object_vars($values) as $name => $value) {
			$properties["{$name}"] = $value;
		}

		return $properties;
	}
}
