<?php
/**
 * Quote of the day for the PIN kiosk.
 *
 * One quote for the Eastern calendar day, the same for every kiosk.
 * Showing it does not change a punch.
 *
 * @package CssTimeclockAddon
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Built-in quotes, custom lines, and the daily pick.
 */
class Css_Tc_Quotes {

	/**
	 * @return array<string,mixed>
	 */
	public static function defaults() {
		return array(
			'quote_enabled' => 0,
			'quote_source'  => 'builtin',
			'quote_custom'  => '',
		);
	}

	/**
	 * Short workplace quotes. No religious or political lines.
	 *
	 * @return array<int,array{text:string,author:string}>
	 */
	public static function builtin() {
		$rows = array(
			array( 'The way to get started is to quit talking and begin doing.', 'Walt Disney' ),
			array( 'Quality means doing it right when no one is looking.', 'Henry Ford' ),
			array( 'Well done is better than well said.', 'Benjamin Franklin' ),
			array( 'Alone we can do so little; together we can do so much.', 'Helen Keller' ),
			array( 'The secret of getting ahead is getting started.', 'Mark Twain' ),
			array( 'Do what you can, with what you have, where you are.', 'Theodore Roosevelt' ),
			array( 'Act as if what you do makes a difference. It does.', 'William James' ),
			array( 'Start where you are. Use what you have. Do what you can.', 'Arthur Ashe' ),
			array( 'The only way to do great work is to love what you do.', 'Steve Jobs' ),
			array( 'Don’t watch the clock; do what it does. Keep going.', 'Sam Levenson' ),
			array( 'A goal without a plan is just a wish.', 'Antoine de Saint-Exupéry' ),
			array( 'Either you run the day or the day runs you.', 'Jim Rohn' ),
			array( 'The future depends on what you do today.', 'Mahatma Gandhi' ),
			array( 'You don’t have to be great to start, but you have to start to be great.', 'Zig Ziglar' ),
			array( 'Make each day your masterpiece.', 'John Wooden' ),
			array( 'Do the hard jobs first. The easy jobs will take care of themselves.', 'Dale Carnegie' ),
			array( 'There are no traffic jams along the extra mile.', 'Roger Staubach' ),
			array( 'The harder I work, the luckier I get.', 'Samuel Goldwyn' ),
			array( 'Opportunity is missed by most people because it is dressed in overalls and looks like work.', 'Thomas Edison' ),
			array( 'The best way to predict the future is to create it.', 'Peter Drucker' ),
			array( 'Done is better than perfect.', 'Sheryl Sandberg' ),
			array( 'Focus on being productive instead of busy.', 'Tim Ferriss' ),
			array( 'If everyone is moving forward together, then success takes care of itself.', 'Henry Ford' ),
			array( 'None of us is as smart as all of us.', 'Ken Blanchard' ),
			array( 'Talent wins games, but teamwork and intelligence win championships.', 'Michael Jordan' ),
			array( 'Great things in business are never done by one person. They’re done by a team.', 'Steve Jobs' ),
			array( 'The strength of the team is each individual member.', 'Phil Jackson' ),
			array( 'Coming together is a beginning. Keeping together is progress. Working together is success.', 'Henry Ford' ),
			array( 'Be so good they can’t ignore you.', 'Steve Martin' ),
			array( 'Whether you think you can, or you think you can’t, you’re right.', 'Henry Ford' ),
			array( 'It always seems impossible until it is done.', 'Nelson Mandela' ),
			array( 'Energy and persistence conquer all things.', 'Benjamin Franklin' ),
			array( 'Our greatest weakness lies in giving up. The certain way to succeed is to try one more time.', 'Thomas Edison' ),
			array( 'Fall seven times and stand up eight.', 'Japanese proverb' ),
			array( 'What you do today can improve all your tomorrows.', 'Ralph Marston' ),
			array( 'Success is the sum of small efforts, repeated day in and day out.', 'Robert Collier' ),
			array( 'We are what we repeatedly do. Excellence, then, is not an act, but a habit.', 'Will Durant' ),
			array( 'The expert in anything was once a beginner.', 'Helen Hayes' ),
			array( 'Don’t let yesterday take up too much of today.', 'Will Rogers' ),
			array( 'I never dreamed about success. I worked for it.', 'Estée Lauder' ),
			array( 'The difference between ordinary and extraordinary is that little extra.', 'Jimmy Johnson' ),
			array( 'You miss 100 percent of the shots you don’t take.', 'Wayne Gretzky' ),
			array( 'Hard work beats talent when talent doesn’t work hard.', 'Tim Notke' ),
			array( 'Pressure is a privilege.', 'Billie Jean King' ),
			array( 'Champions keep playing until they get it right.', 'Billie Jean King' ),
			array( 'I can accept failure. Everyone fails at something. But I can’t accept not trying.', 'Michael Jordan' ),
			array( 'It’s not whether you get knocked down; it’s whether you get up.', 'Vince Lombardi' ),
			array( 'The only place success comes before work is in the dictionary.', 'Vidal Sassoon' ),
			array( 'Don’t count the days. Make the days count.', 'Muhammad Ali' ),
			array( 'If you spend too much time thinking about a thing, you’ll never get it done.', 'Bruce Lee' ),
			array( 'Knowing is not enough; we must apply. Willing is not enough; we must do.', 'Johann Wolfgang von Goethe' ),
			array( 'The person who moves a mountain begins by carrying away small stones.', 'Confucius' ),
			array( 'It is amazing what you can accomplish if you do not care who gets the credit.', 'Harry S. Truman' ),
			array( 'Plans are nothing; planning is everything.', 'Dwight D. Eisenhower' ),
			array( 'Leadership is the capacity to translate vision into reality.', 'Warren Bennis' ),
			array( 'Management is doing things right; leadership is doing the right things.', 'Peter Drucker' ),
			array( 'A leader is one who knows the way, goes the way, and shows the way.', 'John C. Maxwell' ),
			array( 'Seek first to understand, then to be understood.', 'Stephen R. Covey' ),
			array( 'Trust is the glue of life. It’s the most essential ingredient in effective communication.', 'Stephen R. Covey' ),
			array( 'Treat people like they make a difference and they will.', 'Jim Goodnight' ),
			array( 'People will forget what you said, but they will never forget how you made them feel.', 'Maya Angelou' ),
			array( 'Nothing will work unless you do.', 'Maya Angelou' ),
			array( 'Your most unhappy customers are your greatest source of learning.', 'Bill Gates' ),
			array( 'The customer’s perception is your reality.', 'Kate Zabriskie' ),
			array( 'Do what you do so well that they will want to see it again and bring their friends.', 'Walt Disney' ),
			array( 'Price is what you pay. Value is what you get.', 'Warren Buffett' ),
			array( 'It takes twenty years to build a reputation and five minutes to ruin it.', 'Warren Buffett' ),
			array( 'Honesty is the first chapter in the book of wisdom.', 'Thomas Jefferson' ),
			array( 'Simplicity is the ultimate sophistication.', 'Leonardo da Vinci' ),
			array( 'Without data, you’re just another person with an opinion.', 'W. Edwards Deming' ),
			array( 'Quality is everyone’s responsibility.', 'W. Edwards Deming' ),
			array( 'A problem well stated is a problem half solved.', 'Charles Kettering' ),
			array( 'The important thing is not to stop questioning.', 'Albert Einstein' ),
			array( 'Have no fear of perfection; you’ll never reach it.', 'Salvador Dalí' ),
			array( 'The best preparation for tomorrow is doing your best today.', 'H. Jackson Brown Jr.' ),
			array( 'You can’t build a reputation on what you are going to do.', 'Henry Ford' ),
			array( 'Don’t find fault, find a remedy.', 'Henry Ford' ),
			array( 'Believe you can and you’re halfway there.', 'Theodore Roosevelt' ),
			array( 'Example is not the main thing in influencing others. It is the only thing.', 'Albert Schweitzer' ),
			array( 'No act of kindness, no matter how small, is ever wasted.', 'Aesop' ),
			array( 'We rise by lifting others.', 'Robert Ingersoll' ),
			array( 'Punctuality is the soul of business.', 'Thomas Chandler Haliburton' ),
			array( 'Lost time is never found again.', 'Benjamin Franklin' ),
			array( 'Never leave till tomorrow what you can do today.', 'Benjamin Franklin' ),
			array( 'By failing to prepare, you are preparing to fail.', 'Benjamin Franklin' ),
			array( 'An investment in knowledge pays the best interest.', 'Benjamin Franklin' ),
			array( 'The best teamwork comes from people working toward one goal.', 'James Cash Penney' ),
			array( 'There is only one boss. The customer.', 'Sam Walton' ),
			array( 'A satisfied customer is the best business strategy of all.', 'Michael LeBoeuf' ),
			array( 'Outstanding people have one thing in common: an absolute sense of mission.', 'Zig Ziglar' ),
			array( 'Motivation is what gets you started. Habit is what keeps you going.', 'Jim Ryun' ),
			array( 'Setting goals is the first step in turning the invisible into the visible.', 'Tony Robbins' ),
			array( 'You do not rise to the level of your goals. You fall to the level of your systems.', 'James Clear' ),
			array( 'Clear is kind. Unclear is unkind.', 'Brené Brown' ),
			array( 'If you want to lift yourself up, lift up someone else.', 'Booker T. Washington' ),
			array( 'The people who are crazy enough to think they can change the world are the ones who do.', 'Steve Jobs' ),
			array( 'Stay hungry. Stay foolish.', 'Stewart Brand' ),
			array( 'A year from now you may wish you had started today.', 'Karen Lamb' ),
			array( 'Don’t wish it were easier. Wish you were better.', 'Jim Rohn' ),
			array( 'Smooth seas do not make skillful sailors.', 'African proverb' ),
			array( 'If you want to go fast, go alone. If you want to go far, go together.', 'African proverb' ),
			array( 'Individually, we are one drop. Together, we are an ocean.', 'Ryūnosuke Satoro' ),
		);
		$out = array();
		foreach ( $rows as $row ) {
			$out[] = array(
				'text'   => $row[0],
				'author' => $row[1],
			);
		}
		return $out;
	}

	/**
	 * @param array<string,mixed> $settings Plugin settings.
	 * @return array<string,mixed>
	 */
	public static function rules_from( $settings ) {
		$base = self::defaults();
		if ( ! is_array( $settings ) ) {
			$settings = array();
		}
		$source = isset( $settings['quote_source'] ) ? (string) $settings['quote_source'] : $base['quote_source'];
		if ( ! in_array( $source, array( 'builtin', 'custom', 'both' ), true ) ) {
			$source = 'builtin';
		}
		return array(
			'enabled' => empty( $settings['quote_enabled'] ) ? 0 : 1,
			'source'  => $source,
			'custom'  => isset( $settings['quote_custom'] ) ? (string) $settings['quote_custom'] : '',
		);
	}

	/**
	 * @param array<string,mixed> $input Raw fields.
	 * @return array{settings:array<string,mixed>,invalid:string[]}
	 */
	public static function sanitize_input( $input ) {
		if ( ! is_array( $input ) ) {
			$input = array();
		}
		$parsed = self::parse_lines( isset( $input['quote_custom'] ) ? (string) $input['quote_custom'] : '' );
		$rules  = self::rules_from(
			array(
				'quote_enabled' => empty( $input['quote_enabled'] ) ? 0 : 1,
				'quote_source'  => isset( $input['quote_source'] ) ? $input['quote_source'] : 'builtin',
				'quote_custom'  => self::lines_text( $parsed['quotes'] ),
			)
		);
		return array(
			'settings' => array(
				'quote_enabled' => $rules['enabled'],
				'quote_source'  => $rules['source'],
				'quote_custom'  => $rules['custom'],
			),
			'invalid'  => $parsed['invalid'],
		);
	}

	/**
	 * One line is "quote — author". An en dash or " - " is accepted too.
	 *
	 * @param string $text Textarea.
	 * @return array{quotes:array<int,array{text:string,author:string}>,invalid:string[]}
	 */
	public static function parse_lines( $text ) {
		$quotes  = array();
		$invalid = array();
		$lines   = preg_split( '/\r\n|\r|\n/', (string) $text );
		if ( ! is_array( $lines ) ) {
			return array(
				'quotes'  => array(),
				'invalid' => array(),
			);
		}
		foreach ( $lines as $line ) {
			$line = trim( (string) $line );
			if ( '' === $line || '#' === substr( $line, 0, 1 ) ) {
				continue;
			}
			$quote = self::parse_line( $line );
			if ( ! $quote ) {
				$invalid[] = self::clip( $line, 80 );
				continue;
			}
			$quotes[] = $quote;
			if ( count( $quotes ) >= 200 ) {
				break;
			}
		}
		return array(
			'quotes'  => $quotes,
			'invalid' => $invalid,
		);
	}

	/**
	 * @param string $line One line.
	 * @return array{text:string,author:string}|null
	 */
	public static function parse_line( $line ) {
		$line = trim( (string) $line );
		$at   = false;
		$len  = 0;
		foreach ( array( ' — ', ' – ', ' - ' ) as $sep ) {
			$pos = strrpos( $line, $sep );
			if ( false !== $pos ) {
				$at  = $pos;
				$len = strlen( $sep );
				break;
			}
		}
		if ( false === $at || $len < 1 ) {
			return null;
		}
		$text   = self::clip( substr( $line, 0, $at ), 240 );
		$author = self::clip( substr( $line, $at + $len ), 80 );
		if ( '' === $text || '' === $author ) {
			return null;
		}
		return array(
			'text'   => $text,
			'author' => $author,
		);
	}

	/**
	 * @param array<string,mixed> $rules Rules from rules_from().
	 * @return array<int,array{text:string,author:string}>
	 */
	public static function pool( $rules ) {
		$source = isset( $rules['source'] ) ? (string) $rules['source'] : 'builtin';
		$custom = self::parse_lines( isset( $rules['custom'] ) ? (string) $rules['custom'] : '' );
		$list   = array();
		if ( 'custom' !== $source ) {
			$list = self::builtin();
		}
		if ( 'builtin' !== $source ) {
			foreach ( $custom['quotes'] as $quote ) {
				$list[] = $quote;
			}
		}
		return $list;
	}

	/**
	 * Y-m-d in America/New_York, including daylight saving.
	 *
	 * @param int|null $timestamp Unix time. Null means now.
	 * @return string
	 */
	public static function eastern_date( $timestamp = null ) {
		$zone = new DateTimeZone( 'America/New_York' );
		if ( null === $timestamp ) {
			$when = new DateTimeImmutable( 'now', $zone );
		} else {
			$when = ( new DateTimeImmutable( '@' . (int) $timestamp ) )->setTimezone( $zone );
		}
		return $when->format( 'Y-m-d' );
	}

	/**
	 * @param int    $count How many quotes are in the pool.
	 * @param string $ymd   Eastern date.
	 * @return int
	 */
	public static function index_for_date( $count, $ymd ) {
		$count = (int) $count;
		if ( $count < 1 ) {
			return 0;
		}
		$n = hexdec( hash( 'crc32b', (string) $ymd ) );
		return (int) ( $n % $count );
	}

	/**
	 * The quote for this Eastern day, or null when the feature is off or the list is empty.
	 *
	 * @param array<string,mixed> $settings  Plugin settings.
	 * @param int|null            $timestamp Unix time for tests.
	 * @return array{text:string,author:string,date:string}|null
	 */
	public static function present( $settings, $timestamp = null ) {
		$rules = self::rules_from( $settings );
		if ( empty( $rules['enabled'] ) ) {
			return null;
		}
		$pool = self::pool( $rules );
		if ( ! $pool ) {
			return null;
		}
		$date  = self::eastern_date( $timestamp );
		$quote = $pool[ self::index_for_date( count( $pool ), $date ) ];
		return array(
			'text'   => $quote['text'],
			'author' => $quote['author'],
			'date'   => $date,
		);
	}

	/**
	 * @param array<int,array{text:string,author:string}> $quotes Quotes.
	 * @return string
	 */
	private static function lines_text( $quotes ) {
		$lines = array();
		foreach ( $quotes as $quote ) {
			$lines[] = $quote['text'] . ' — ' . $quote['author'];
		}
		return implode( "\n", $lines );
	}

	/**
	 * @param string $text  Text.
	 * @param int    $limit Max characters.
	 * @return string
	 */
	private static function clip( $text, $limit ) {
		$text = trim( strip_tags( (string) $text ) );
		if ( function_exists( 'mb_substr' ) ) {
			return mb_substr( $text, 0, $limit );
		}
		return substr( $text, 0, $limit );
	}
}
