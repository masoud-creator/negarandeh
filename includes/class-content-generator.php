<?php
/**
 * AI content generation with prompt templates and placeholders.
 *
 * @package Negarandeh
 */

defined( 'ABSPATH' ) || exit;

class NEGARANDEH_Content_Generator {

	/**
	 * Available placeholders for prompt templates.
	 *
	 * @return array<string,string>
	 */
	public static function get_placeholders_help(): array {
		return array(
			'{topic}'         => __( 'موضوع فعلی در لیست (مثلاً یادگیری ماشین)', 'negarandeh' ),
			'{total_topics}'  => __( 'تعداد کل موضوعات', 'negarandeh' ),
			'{site_name}'     => __( 'نام سایت وردپرس', 'negarandeh' ),
			'{site_url}'      => __( 'آدرس سایت', 'negarandeh' ),
			'{today}'         => __( 'تاریخ امروز', 'negarandeh' ),
		);
	}

	/**
	 * Placeholders for the featured-image prompt template.
	 *
	 * @return array<string,string>
	 */
	public static function get_image_placeholders_help(): array {
		return array(
			'{topic}'         => __( 'موضوع فعلی', 'negarandeh' ),
			'{title}'         => __( 'عنوان مقاله تولیدشده', 'negarandeh' ),
			'{focus_keyword}' => __( 'کلمه کلیدی SEO', 'negarandeh' ),
			'{image_alt}'     => __( 'متن alt پیشنهادی AI', 'negarandeh' ),
			'{site_name}'     => __( 'نام سایت', 'negarandeh' ),
			'{today}'         => __( 'تاریخ امروز', 'negarandeh' ),
		);
	}

	/**
	 * Replace placeholders in template.
	 *
	 * @param string              $template User prompt template.
	 * @param string              $topic    Current topic label.
	 * @param array<string,mixed> $context  Extra context.
	 */
	public static function build_user_prompt( string $template, string $topic, array $context = array() ): string {
		$topics = $context['topics'] ?? array();

		$replacements = array(
			'{topic}'        => $topic,
			'{total_topics}' => (string) count( $topics ),
			'{site_name}'    => get_bloginfo( 'name' ),
			'{site_url}'     => home_url(),
			'{today}'        => wp_date( 'Y/m/d' ),
		);

		return str_replace( array_keys( $replacements ), array_values( $replacements ), $template );
	}

	/**
	 * Replace placeholders in the featured-image prompt template.
	 *
	 * @param string              $template Image prompt template.
	 * @param string              $topic    Current topic.
	 * @param array<string,mixed> $context  Job context.
	 * @param array<string,mixed> $content  Parsed article content (title, keywords, …).
	 */
	public static function build_image_prompt( string $template, string $topic, array $context = array(), array $content = array() ): string {
		$topics = $context['topics'] ?? array();

		$replacements = array(
			'{topic}'         => $topic,
			'{total_topics}'  => (string) count( $topics ),
			'{site_name}'     => get_bloginfo( 'name' ),
			'{site_url}'      => home_url(),
			'{today}'         => wp_date( 'Y/m/d' ),
			'{title}'         => sanitize_text_field( (string) ( $content['title'] ?? '' ) ),
			'{excerpt}'       => sanitize_text_field( (string) ( $content['excerpt'] ?? '' ) ),
			'{focus_keyword}' => sanitize_text_field( (string) ( $content['focus_keyword'] ?? '' ) ),
			'{image_alt}'     => sanitize_text_field( (string) ( $content['image_alt'] ?? '' ) ),
		);

		return str_replace( array_keys( $replacements ), array_values( $replacements ), $template );
	}

	/**
	 * Generate blog content for one topic.
	 *
	 * @param string              $topic   Topic label.
	 * @param array<string,mixed> $context Context (topics list, index, etc.).
	 * @return array<string,mixed>|WP_Error Parsed content array.
	 */
	public static function generate_for_topic( string $topic, array $context = array() ) {
		$gen_settings = get_option( 'negarandeh_generator_settings', array() );
		$template     = trim( (string) ( $gen_settings['prompt_template'] ?? '' ) );
		if ( '' === $template ) {
			$content_lang = in_array( $gen_settings['language'] ?? '', array( 'fa', 'en' ), true )
				? (string) $gen_settings['language']
				: null;
			$template = self::default_prompt_template( $content_lang );
		}
		$user_prompt  = self::build_user_prompt( $template, $topic, $context );

		$system = self::build_system_prompt( $gen_settings );

		$api_settings = NEGARANDEH_Avalai_API::get_settings();
		$word_count   = (int) ( $gen_settings['word_count'] ?? 2500 );
		$max_tokens   = max( (int) ( $api_settings['max_tokens'] ?? 4096 ), 8000 );
		if ( $word_count >= 1000 ) {
			$max_tokens = max( $max_tokens, 12000 );
		}

		$response = NEGARANDEH_Avalai_API::chat(
			array(
				array(
					'role'    => 'system',
					'content' => $system,
				),
				array(
					'role'    => 'user',
					'content' => $user_prompt,
				),
			),
			array(
				'max_tokens' => min( 16000, $max_tokens ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$raw = $response['choices'][0]['message']['content'] ?? '';
		if ( is_array( $raw ) ) {
			$parts = array();
			foreach ( $raw as $part ) {
				if ( is_string( $part ) ) {
					$parts[] = $part;
					continue;
				}
				if ( is_array( $part ) && ! empty( $part['text'] ) && is_string( $part['text'] ) ) {
					$parts[] = $part['text'];
				}
			}
			$raw = implode( "\n", $parts );
		}
		$raw = trim( (string) $raw );

		if ( '' === $raw ) {
			return new WP_Error( 'negarandeh_empty_response', __( 'پاسخ خالی از API دریافت شد.', 'negarandeh' ) );
		}

		$parsed = self::parse_ai_json_response( $raw );
		if ( null === $parsed ) {
			$finish_reason = (string) ( $response['choices'][0]['finish_reason'] ?? '' );
			$looks_truncated = 'length' === $finish_reason
				|| ( str_contains( $raw, '"title"' ) && ! str_contains( $raw, '"content"' ) );

			$message = $looks_truncated
				? __( 'پاسخ AI ناقص بود (احتمالاً max_tokens کم است). در تنظیمات API مقدار «حداکثر توکن» را به 12000–16000 برسانید.', 'negarandeh' )
				: __( 'فرمت JSON پاسخ AI نامعتبر است.', 'negarandeh' );

			return new WP_Error(
				$looks_truncated ? 'negarandeh_truncated_json' : 'negarandeh_invalid_json',
				$message,
				array(
					'raw_excerpt'   => self::truncate_raw_excerpt( $raw ),
					'finish_reason' => $finish_reason,
				)
			);
		}

		$content = trim( (string) ( $parsed['content'] ?? '' ) );
		if ( '' === $content || ! self::article_html_looks_valid( $content ) ) {
			return new WP_Error(
				'negarandeh_invalid_article_html',
				__( 'متن مقاله در پاسخ AI نامعتبر یا همراه با توضیح اضافی غیرقابل‌پاک‌سازی بود.', 'negarandeh' ),
				array(
					'raw_excerpt' => self::truncate_raw_excerpt( $raw ),
				)
			);
		}

		$parsed['content'] = $content;

		return $parsed;
	}

	/**
	 * Extract and decode JSON article payload from model output (Gemini-safe).
	 *
	 * @return array<string,mixed>|null
	 */
	public static function parse_ai_json_response( string $raw ): ?array {
		$raw = trim( preg_replace( '/^\xEF\xBB\xBF/', '', $raw ) );
		if ( '' === $raw ) {
			return null;
		}

		$candidates = array();

		$unwrapped = self::unwrap_markdown_json_fence( $raw );
		if ( '' !== $unwrapped ) {
			$candidates[] = $unwrapped;
		}

		$candidates[] = $raw;

		if ( preg_match( '/```(?:json)?\s*([\s\S]+)/i', $raw, $matches ) ) {
			$block = trim( $matches[1] );
			$block = preg_replace( '/```\s*$/', '', $block );
			if ( '' !== trim( $block ) ) {
				$candidates[] = trim( $block );
			}
		}

		$extracted = self::extract_json_object( $unwrapped ?: $raw );
		if ( null !== $extracted ) {
			$candidates[] = $extracted;
		}

		$candidates = array_values( array_unique( array_filter( array_map( 'trim', $candidates ) ) ) );

		foreach ( $candidates as $json ) {
			$parsed = self::decode_json_lenient( $json );
			if ( null === $parsed ) {
				continue;
			}

			$parsed = self::normalize_content_payload( $parsed );
			if ( ! empty( $parsed['title'] ) ) {
				return $parsed;
			}
		}

		return null;
	}

	/**
	 * Remove ```json fences even when closing fence is missing (truncated stream).
	 */
	private static function unwrap_markdown_json_fence( string $raw ): string {
		$raw = trim( $raw );
		if ( ! preg_match( '/^```(?:json)?\s*/i', $raw ) ) {
			return $raw;
		}

		$raw = preg_replace( '/^```(?:json)?\s*/i', '', $raw );

		return trim( preg_replace( '/```\s*$/', '', trim( (string) $raw ) ) );
	}

	/**
	 * @return array<string,mixed>|null
	 */
	private static function decode_json_lenient( string $json ): ?array {
		$parsed = json_decode( $json, true );
		if ( JSON_ERROR_NONE === json_last_error() && is_array( $parsed ) ) {
			return $parsed;
		}

		// Some models emit literal newlines/tabs inside JSON strings.
		$repaired = preg_replace( "/[\r\n\t]+/", ' ', $json );
		if ( is_string( $repaired ) && $repaired !== $json ) {
			$parsed = json_decode( $repaired, true );
			if ( JSON_ERROR_NONE === json_last_error() && is_array( $parsed ) ) {
				return $parsed;
			}
		}

		return null;
	}

	/**
	 * Find the first balanced {...} object in text.
	 */
	private static function extract_json_object( string $text ): ?string {
		$start = strpos( $text, '{' );
		if ( false === $start ) {
			return null;
		}

		$depth     = 0;
		$in_string = false;
		$escape    = false;
		$length    = strlen( $text );

		for ( $i = $start; $i < $length; $i++ ) {
			$char = $text[ $i ];

			if ( $in_string ) {
				if ( $escape ) {
					$escape = false;
					continue;
				}
				if ( '\\' === $char ) {
					$escape = true;
					continue;
				}
				if ( '"' === $char ) {
					$in_string = false;
				}
				continue;
			}

			if ( '"' === $char ) {
				$in_string = true;
				continue;
			}
			if ( '{' === $char ) {
				++$depth;
			} elseif ( '}' === $char ) {
				--$depth;
				if ( 0 === $depth ) {
					return substr( $text, $start, $i - $start + 1 );
				}
			}
		}

		return null;
	}

	/**
	 * @param array<string,mixed> $parsed Decoded JSON.
	 * @return array<string,mixed>
	 */
	private static function normalize_content_payload( array $parsed ): array {
		$aliases = array(
			'Title'            => 'title',
			'Content'          => 'content',
			'Excerpt'          => 'excerpt',
			'MetaTitle'        => 'meta_title',
			'metaTitle'        => 'meta_title',
			'MetaDescription'  => 'meta_description',
			'metaDescription'  => 'meta_description',
			'FocusKeyword'     => 'focus_keyword',
			'Slug'             => 'slug',
			'ImagePrompt'      => 'image_prompt',
			'ImageAlt'         => 'image_alt',
			'Tags'             => 'tags',
			'post_tags'        => 'tags',
			'postTags'         => 'tags',
		);

		foreach ( $aliases as $from => $to ) {
			if ( empty( $parsed[ $to ] ) && ! empty( $parsed[ $from ] ) ) {
				$parsed[ $to ] = $parsed[ $from ];
			}
		}

		if ( empty( $parsed['title'] ) && ! empty( $parsed['heading'] ) ) {
			$parsed['title'] = $parsed['heading'];
		}

		if ( isset( $parsed['tags'] ) ) {
			$parsed['tags'] = self::normalize_tags_list( $parsed['tags'] );
		}

		if ( isset( $parsed['content'] ) && is_string( $parsed['content'] ) ) {
			$parsed['content'] = self::clean_article_html( $parsed['content'] );
		}

		// Short text fields: only drop obvious chat-meta (never invent replacements).
		foreach ( array( 'excerpt', 'meta_description', 'image_alt' ) as $field ) {
			if ( ! isset( $parsed[ $field ] ) || ! is_string( $parsed[ $field ] ) ) {
				continue;
			}
			$clean = self::strip_ai_meta_text( $parsed[ $field ] );
			if ( '' !== $clean ) {
				$parsed[ $field ] = $clean;
			} elseif ( self::text_looks_like_ai_meta( trim( $parsed[ $field ] ) ) ) {
				$parsed[ $field ] = '';
			}
		}

		return $parsed;
	}

	/**
	 * Keep only article HTML; strip chat prefaces, postambles, and markdown fences.
	 */
	public static function clean_article_html( string $html ): string {
		$html = trim( $html );
		if ( '' === $html ) {
			return '';
		}

		// Unwrap fenced HTML blocks the model sometimes returns inside JSON.
		if ( preg_match( '/^```(?:html)?\s*([\s\S]*?)```\s*$/i', $html, $matches ) ) {
			$html = trim( (string) $matches[1] );
		}

		// Drop plain-text commentary before the first article tag.
		if ( preg_match( '/<(?:p|h[2-6]|ul|ol|table|div|section|blockquote|figure)\b/i', $html, $matches, PREG_OFFSET_CAPTURE ) ) {
			$pos = (int) $matches[0][1];
			if ( $pos > 0 ) {
				$prefix = trim( substr( $html, 0, $pos ) );
				if ( '' !== $prefix && ! preg_match( '/<[a-z][\w:-]*\b/i', $prefix ) ) {
					$html = substr( $html, $pos );
				}
			}
		}

		// Drop trailing plain text after the last closing tag.
		if ( preg_match( '/^(.*<\/[a-z][\w:-]*>)\s*(.+)$/is', $html, $matches ) ) {
			$tail = trim( (string) $matches[2] );
			if ( '' !== $tail && ! preg_match( '/<[a-z][\w:-]*\b/i', $tail ) && self::text_looks_like_ai_meta( $tail ) ) {
				$html = trim( (string) $matches[1] );
			}
		}

		$html = self::strip_ai_meta_paragraphs( $html );

		return trim( $html );
	}

	/**
	 * Whether cleaned HTML looks like a real article body.
	 */
	public static function article_html_looks_valid( string $html ): bool {
		$html = trim( $html );
		if ( '' === $html ) {
			return false;
		}

		if ( preg_match( '/<(?:p|h[2-6]|ul|ol|table)\b/i', $html ) ) {
			return true;
		}

		// Plain text fallback: require enough substance and no chat-meta opener.
		$plain = trim( wp_strip_all_tags( $html ) );
		if ( mb_strlen( $plain ) < 200 ) {
			return false;
		}

		return ! self::text_looks_like_ai_meta( mb_substr( $plain, 0, 180 ) );
	}

	/**
	 * Remove leading/trailing <p> blocks that are chat-style meta, not article copy.
	 */
	private static function strip_ai_meta_paragraphs( string $html ): string {
		$max_rounds = 6;
		for ( $i = 0; $i < $max_rounds; $i++ ) {
			$changed = false;

			if ( preg_match( '/^\s*<p\b[^>]*>([\s\S]*?)<\/p>\s*/iu', $html, $matches ) ) {
				$inner = trim( wp_strip_all_tags( (string) $matches[1] ) );
				if ( self::text_looks_like_ai_meta( $inner ) ) {
					$html    = (string) preg_replace( '/^\s*<p\b[^>]*>[\s\S]*?<\/p>\s*/iu', '', $html, 1 );
					$changed = true;
				}
			}

			if ( preg_match( '/\s*<p\b[^>]*>([\s\S]*?)<\/p>\s*$/iu', $html, $matches ) ) {
				$inner = trim( wp_strip_all_tags( (string) $matches[1] ) );
				if ( self::text_looks_like_ai_meta( $inner ) ) {
					$html    = (string) preg_replace( '/\s*<p\b[^>]*>[\s\S]*?<\/p>\s*$/iu', '', $html, 1 );
					$changed = true;
				}
			}

			if ( ! $changed ) {
				break;
			}
		}

		return trim( $html );
	}

	/**
	 * Strip common AI meta phrases from short text fields.
	 */
	private static function strip_ai_meta_text( string $text ): string {
		$text = trim( $text );
		if ( '' === $text ) {
			return '';
		}

		$text = preg_replace( '/^```(?:json|html)?\s*|\s*```$/iu', '', $text );
		$text = trim( (string) $text );

		if ( self::text_looks_like_ai_meta( $text ) && mb_strlen( $text ) < 220 ) {
			return '';
		}

		return $text;
	}

	/**
	 * Detect chat-style commentary that must never appear in the published post.
	 */
	private static function text_looks_like_ai_meta( string $text ): bool {
		$text = trim( preg_replace( '/\s+/u', ' ', $text ) );
		if ( '' === $text ) {
			return false;
		}

		$patterns = array(
			'/^(البته[!!.]?\s*|حتما[ًا]?[!!.]?\s*|چشم[!!.]?\s*)/u',
			'/^(در ادامه|در اینجا|در زیر|طبق درخواست|همان[\s‌]?طور که خواستید|طبق خواسته شما)/u',
			'/^(این مقاله را|مقاله[‌ ]?(زیر|زیرین|زیر را)|مقاله[‌ ]?ای (جامع|کامل|آموزشی)|در ادامه مقاله)/u',
			'/^(Sure[!.,]?\s*|Certainly[!.,]?\s*|Of course[!.,]?\s*|Absolutely[!.,]?\s*)/iu',
			'/^(Here(?:\'s| is)|Below is|I(?:\'ve| have) (?:written|prepared|created)|As requested)/iu',
			'/^(JSON|Note:|Disclaimer:|As an AI|I(?:\'m| am) an AI)/iu',
			'/(امیدوارم (این )?(مقاله|مطالب).{0,40}(مفید|سودمند)|اگر سوالی دارید|در صورت نیاز بپرسید)/u',
			'/(I hope (this|the) (article|guide|post).{0,40}(helps|useful)|Let me know if|Feel free to (ask|reach out)|If you (?:have|need) any (?:questions|further))/iu',
			'/(فقط مقاله را برگردان|هیچ توضیح اضافه|پاسخ فقط JSON|respond with valid JSON only)/iu',
		);

		foreach ( $patterns as $pattern ) {
			if ( preg_match( $pattern, $text ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Normalize AI tags into a clean list of strings.
	 *
	 * @param mixed $tags Raw tags from AI (array or string).
	 * @return array<int,string>
	 */
	public static function normalize_tags_list( $tags ): array {
		if ( is_string( $tags ) ) {
			$tags = preg_split( '/[,،|;]+/u', $tags ) ?: array();
		}

		if ( ! is_array( $tags ) ) {
			return array();
		}

		$clean = array();
		foreach ( $tags as $tag ) {
			if ( is_array( $tag ) ) {
				$tag = $tag['name'] ?? $tag['tag'] ?? reset( $tag );
			}
			$tag = sanitize_text_field( (string) $tag );
			$tag = trim( $tag, " \t\n\r\0\x0B\"'" );
			if ( '' === $tag ) {
				continue;
			}
			$key = function_exists( 'mb_strtolower' ) ? mb_strtolower( $tag ) : strtolower( $tag );
			$clean[ $key ] = $tag;
		}

		return array_values( $clean );
	}

	/**
	 * @param string $raw Raw model output.
	 */
	private static function truncate_raw_excerpt( string $raw ): string {
		$raw = trim( $raw );
		if ( mb_strlen( $raw ) <= 600 ) {
			return $raw;
		}

		return mb_substr( $raw, 0, 600 ) . '…';
	}

	/**
	 * @param array<string,mixed> $settings Generator settings.
	 */
	private static function build_system_prompt( array $settings ): string {
		$word_count = (int) ( $settings['word_count'] ?? 2500 );
		$language   = sanitize_text_field( $settings['language'] ?? 'fa' );
		$tag_count  = max( 1, min( 15, (int) ( $settings['tag_count'] ?? 5 ) ) );
		$want_tags  = ! empty( $settings['generate_tags'] );

		$lang_label = 'fa' === $language ? 'فارسی روان و حرفه‌ای' : 'English';
		$site_name  = get_bloginfo( 'name' );

		$api_settings = NEGARANDEH_Avalai_API::get_settings();
		$chat_model = trim( (string) ( $api_settings['chat_model'] ?? '' ) );
		$gemini_note  = '';

		if ( NEGARANDEH_Avalai_API::is_gemini_model( $chat_model ) ) {
			$gemini_note = "\n\nCRITICAL: Reply with ONE raw JSON object only. Start with { and end with }. No markdown fences, no text before or after JSON.";
		}

		$tags_line = '';
		if ( $want_tags ) {
			$tags_line = '  "tags": ["tag1", "tag2"],' . "\n";
		}

		return 'You are an expert SEO content writer for blogs and websites.' . "\n\n"
			. 'Write in ' . $lang_label . '. Target audience: readers of the site "' . $site_name . '".' . "\n\n"
			. 'You MUST respond with valid JSON only (no markdown outside JSON). Use this exact structure:' . "\n"
			. '{' . "\n"
			. '  "title": "SEO-optimized H1 title (50-60 chars ideal, include focus keyword)",' . "\n"
			. '  "slug": "url-friendly-slug-with-hyphens",' . "\n"
			. '  "excerpt": "Meta excerpt 150-160 characters",' . "\n"
			. '  "meta_title": "SEO title tag max 60 chars",' . "\n"
			. '  "meta_description": "SEO meta description 150-160 chars with CTA",' . "\n"
			. '  "focus_keyword": "primary keyword phrase",' . "\n"
			. $tags_line
			. '  "content": "Full HTML article with proper structure: intro paragraph, H2/H3 headings, bullet lists where useful, comparison tables if relevant, FAQ section with 3-5 questions at the end. Use <p>, <h2>, <h3>, <ul>, <li>, <strong>. Do NOT use H1 in content. Minimum ' . (string) $word_count . ' words.",' . "\n"
			. '  "image_prompt": "Detailed English prompt for a wide landscape 16:9 blog featured image (1200x675): photorealistic or clean illustration, horizontal composition, subject centered for thumbnail crop, no text overlay, related to the article topic",' . "\n"
			. '  "image_alt": "Accessible alt text for the featured image including the topic and keyword"' . "\n"
			. '}' . "\n\n"
			. 'SEO rules:' . "\n"
			. '- Use focus keyword in title, first paragraph, one H2, and naturally 2-3 times in body' . "\n"
			. '- Write unique, helpful content — avoid fluff and generic filler' . "\n"
			. '- Include internal linking suggestions as HTML comments: <!-- internal-link: anchor text -->' . "\n"
			. '- FAQ section helps featured snippets' . "\n"
			. 'Content field rules:' . "\n"
			. '- "content" must contain ONLY the article HTML body' . "\n"
			. '- Do NOT add chat prefaces like "Sure", "Here is the article", "در ادامه مقاله", or closing remarks like "I hope this helps"' . "\n"
			. '- Do NOT wrap content in markdown fences'
			. ( $want_tags ? "\n" . '- Provide exactly ' . (string) $tag_count . ' concise, relevant tags (no duplicates, no generic words like "blog")' : '' )
			. $gemini_note;
	}

	/**
	 * Build final image prompt for AvalAI Image API.
	 *
	 * Priority: user template → AI image_prompt → auto fallback.
	 *
	 * @param array<string,mixed> $content Parsed AI content.
	 * @param array<string,mixed> $job     Queue job data.
	 */
	public static function resolve_image_prompt( array $content, array $job ): string {
		$settings = get_option( 'negarandeh_generator_settings', array() );
		$topic    = sanitize_text_field( $job['topic'] ?? '' );
		$template = trim( $settings['image_prompt_template'] ?? '' );
		$list     = $job['topics'] ?? array();
		$context  = array(
			'topics' => $list,
			'index'  => $job['index'] ?? 1,
		);

		if ( $template ) {
			return mb_substr(
				self::build_image_prompt( $template, $topic, $context, $content ),
				0,
				4000
			);
		}

		if ( ! empty( $content['image_prompt'] ) ) {
			return mb_substr( trim( (string) $content['image_prompt'] ), 0, 4000 );
		}

		if ( $topic ) {
			return mb_substr(
				sprintf(
					'Professional photorealistic 16:9 blog featured image about %s, modern clean composition, professional lighting, title text on a semi-transparent modern box, no logo, no watermark, high quality',
					$topic
				),
				0,
				4000
			);
		}

		return '';
	}

	public static function default_image_prompt_template(): string {
		if ( NEGARANDEH_I18n::LANG_EN === NEGARANDEH_I18n::get_lang() ) {
			return <<<'PROMPT'
Create a professional featured image for a blog article about "{topic}".
Article title:
"{title}"
Design requirements:
- The image must match the title and topic.
- The image must be fully relevant to the article subject.
- Place the article title in English on the image:
"{title}"

Text design:
- Large, readable, professional English typography.
- Put the title inside a modern box with a semi-transparent background.
- Text color and contrast must stay fully readable over the image.
- Title styling should look like professional educational and news site covers.

Image style:
- Fully photorealistic
- Modern and clean design
- Professional lighting
- Suitable as a featured image for an SEO article

Composition:
- 16:9 aspect ratio
- Enough clear space for the title
- Clean, professional background
- No logo, no brand, no watermark

Quality:
- Very high quality
- Sharp details
- Suitable for website and social media display
- Attractive design to improve click-through rate
PROMPT;
		}

		return <<<'PROMPT'
یک تصویر شاخص حرفه‌ای برای مقاله وبلاگی با موضوع "{topic}" ایجاد کن.
عنوان مقاله:
"{title}"
الزامات طراحی:
- تصویر متناسب با عنوان و موضوع باشد.
- تصویر باید کاملاً مرتبط با موضوع مقاله باشد.
- عنوان مقاله را به زبان فارسی روی تصویر قرار بده:
"{title}"

طراحی متن:
- متن فارسی بزرگ و فونت وزیر ، خوانا و حرفه‌ای باشد.
- عنوان داخل یک کادر مدرن با پس‌زمینه نیمه شفاف قرار بگیرد.
- رنگ و کنتراست متن طوری باشد که روی تصویر کاملاً خوانا باشد.
- ظاهر عنوان مانند کاورهای حرفه‌ای سایت‌های آموزشی و خبری باشد.

سبک تصویر:
- کاملاً واقع‌گرایانه (Photorealistic)
- طراحی مدرن و تمیز
- نورپردازی حرفه‌ای
- مناسب تصویر شاخص مقاله سئو شده

ترکیب‌بندی:
- نسبت تصویر 16:9 باشد.
- فضای کافی برای قرارگیری عنوان وجود داشته باشد.
- پس‌زمینه مرتب و حرفه‌ای باشد.
- بدون لوگو، بدون برند، بدون واترمارک.

کیفیت:
- کیفیت بسیار بالا
- جزئیات واضح
- مناسب نمایش در سایت و شبکه‌های اجتماعی
- طراحی جذاب برای افزایش نرخ کلیک کاربران
PROMPT;
	}

	public static function default_prompt_template( ?string $content_language = null ): string {
		if ( null === $content_language || ! in_array( $content_language, array( 'fa', 'en' ), true ) ) {
			$gen = get_option( 'negarandeh_generator_settings', array() );
			if ( is_array( $gen ) && in_array( $gen['language'] ?? '', array( 'fa', 'en' ), true ) ) {
				$content_language = (string) $gen['language'];
			} else {
				$content_language = NEGARANDEH_I18n::get_lang();
			}
		}

		return self::compose_prompt_template_from_preferences(
			array(
				'language'           => $content_language,
				'word_count'         => 2500,
				'tone'               => 'professional',
				'audience'           => '',
				'include_faq'        => true,
				'include_toc'        => true,
				'include_intro'      => true,
				'include_conclusion' => true,
				'seo_focus'          => true,
				'notes'              => '',
			)
		);
	}

	/**
	 * Build a full article prompt template from builder preferences (local, no API).
	 *
	 * @param array<string,mixed> $args Builder options.
	 */
	public static function compose_prompt_template_from_preferences( array $args ): string {
		$word_count = max( 400, min( 5000, (int) ( $args['word_count'] ?? 2500 ) ) );
		$audience   = sanitize_text_field( (string) ( $args['audience'] ?? '' ) );
		$tone       = sanitize_key( (string) ( $args['tone'] ?? 'professional' ) );
		$language   = in_array( $args['language'] ?? '', array( 'fa', 'en' ), true )
			? (string) $args['language']
			: NEGARANDEH_I18n::get_lang();
		$notes      = sanitize_textarea_field( (string) ( $args['notes'] ?? '' ) );

		$include_faq        = ! empty( $args['include_faq'] );
		$include_toc        = ! empty( $args['include_toc'] );
		$include_intro      = ! array_key_exists( 'include_intro', $args ) || ! empty( $args['include_intro'] );
		$include_conclusion = ! array_key_exists( 'include_conclusion', $args ) || ! empty( $args['include_conclusion'] );
		$seo_focus          = ! array_key_exists( 'seo_focus', $args ) || ! empty( $args['seo_focus'] );

		if ( NEGARANDEH_I18n::LANG_EN === $language ) {
			return self::compose_prompt_template_en(
				$word_count,
				$audience,
				$tone,
				$include_faq,
				$include_toc,
				$include_intro,
				$include_conclusion,
				$seo_focus,
				$notes
			);
		}

		return self::compose_prompt_template_fa(
			$word_count,
			$audience,
			$tone,
			$include_faq,
			$include_toc,
			$include_intro,
			$include_conclusion,
			$seo_focus,
			$notes
		);
	}

	/**
	 * @param int    $word_count Word count.
	 * @param string $audience   Target audience.
	 * @param string $tone       Tone key.
	 */
	private static function compose_prompt_template_fa(
		int $word_count,
		string $audience,
		string $tone,
		bool $include_faq,
		bool $include_toc,
		bool $include_intro,
		bool $include_conclusion,
		bool $seo_focus,
		string $notes
	): string {
		$tone_line = self::get_prompt_tone_label_fa( $tone );
		$audience  = '' !== $audience ? $audience : 'مبتدی تا متوسط';

		$sections = array( 'عنوان مقاله (H1)' );
		if ( $include_intro ) {
			$sections[] = 'مقدمه';
		}
		if ( $include_toc ) {
			$sections[] = 'فهرست مطالب';
		}
		$sections[] = 'بدنه مقاله';

		$body_blocks = array(
			'نکات مهم',
			'اشتباهات رایج',
			'ترفندهای کاربردی',
			'جدول‌های مقایسه‌ای',
			'چک‌لیست',
		);
		if ( $include_faq ) {
			$body_blocks[] = 'سوالات متداول';
		}
		if ( $include_conclusion ) {
			$sections[] = 'جمع‌بندی';
		}

		$prompt = "نقش:\n"
			. "شما یک نویسنده حرفه‌ای محتوا و متخصص سئو هستید. یک مقاله آموزشی جامع، کاملاً یونیک، کاربردی و سئو شده درباره موضوع زیر تولید کنید.\n\n"
			. "موضوع: {topic}\n\n"
			. "هدف\n"
			. "مقاله باید نیاز مخاطب را به‌طور کامل برطرف کند، ارزش واقعی ارائه دهد و از نظر کیفیت، خوانایی و سئو با مقالات برتر گوگل رقابت کند.\n\n"
			. "قوانین\n"
			. "زبان: فارسی روان\n"
			. 'لحن: ' . $tone_line . "\n"
			. 'مخاطب: ' . $audience . "\n"
			. 'حداقل ' . (string) $word_count . " کلمه\n"
			. "متن کاملاً طبیعی و انسانی باشد.\n"
			. "از تکرار، کلی‌گویی و عبارت‌های کلیشه‌ای هوش مصنوعی خودداری کن.\n"
			. "پاراگراف‌ها کوتاه باشند.\n"
			. "در صورت نیاز از مثال، جدول، لیست، چک‌لیست و مقایسه استفاده کن.\n";

		if ( $seo_focus ) {
			$prompt .= "\nکلمه کلیدی اصلی:\n"
				. "{topic}\n\n"
				. "آن را به‌صورت طبیعی در موارد زیر استفاده کن:\n"
				. "عنوان\n"
				. "توضیحات متا\n"
				. "مقدمه\n"
				. "حداقل یک H2\n"
				. "چند H3\n"
				. "جمع‌بندی\n\n"
				. "از کلمات کلیدی مرتبط (Semantic Keywords) نیز به‌صورت طبیعی استفاده کن.\n";
		}

		$prompt .= "\nساختار خروجی\n"
			. "ترتیب بخش‌ها:\n"
			. implode( "\n", $sections ) . "\n"
			. "محتوا را با تیترهای H2 و H3 سازمان‌دهی کن و در صورت نیاز از موارد زیر استفاده کن:\n\n"
			. implode( "\n", $body_blocks ) . "\n";

		if ( $include_faq ) {
			$prompt .= "۸ تا ۱۲ سوال متداول با پاسخ کامل و کاربردی.\n";
		}

		if ( $include_conclusion ) {
			$prompt .= "جمع‌بندی\n"
				. "مهم‌ترین نکات را خلاصه کن و خواننده را به استفاده از مطالب تشویق کن.\n";
		}

		if ( '' !== trim( $notes ) ) {
			$prompt .= "\nتوضیحات تکمیلی:\n" . $notes . "\n";
		}

		$prompt .= "\nقبل از ارسال بررسی کن:\n"
			. "- کاملاً یونیک باشد.\n"
			. "- سئو شده باشد.\n"
			. "- نگارش روان و طبیعی داشته باشد.\n"
			. "- ارزش واقعی برای کاربر ایجاد کند.\n"
			. "- فاقد متن تکراری و جملات کلیشه‌ای باشد.\n"
			. "- فقط مقاله را برگردان و هیچ توضیح اضافه‌ای ننویس.";

		return $prompt;
	}

	/**
	 * @param int    $word_count Word count.
	 * @param string $audience   Target audience.
	 * @param string $tone       Tone key.
	 */
	private static function compose_prompt_template_en(
		int $word_count,
		string $audience,
		string $tone,
		bool $include_faq,
		bool $include_toc,
		bool $include_intro,
		bool $include_conclusion,
		bool $seo_focus,
		string $notes
	): string {
		$tone_line = self::get_prompt_tone_label_en( $tone );
		$audience  = '' !== $audience ? $audience : 'beginner to intermediate';

		$sections = array( 'Article title (H1)' );
		if ( $include_intro ) {
			$sections[] = 'Introduction';
		}
		if ( $include_toc ) {
			$sections[] = 'Table of contents';
		}
		$sections[] = 'Article body';

		$body_blocks = array(
			'Key points',
			'Common mistakes',
			'Practical tips',
			'Comparison tables',
			'Checklist',
		);
		if ( $include_faq ) {
			$body_blocks[] = 'FAQ';
		}
		if ( $include_conclusion ) {
			$sections[] = 'Conclusion';
		}

		$prompt = "Role:\n"
			. "You are a professional content writer and SEO specialist. Produce a comprehensive, fully unique, practical, and SEO-optimized educational article on the topic below.\n\n"
			. "Topic: {topic}\n\n"
			. "Goal\n"
			. "The article must fully meet the reader's needs, deliver real value, and compete with top Google results in quality, readability, and SEO.\n\n"
			. "Rules\n"
			. "Language: clear English\n"
			. 'Tone: ' . $tone_line . "\n"
			. 'Audience: ' . $audience . "\n"
			. 'Minimum ' . (string) $word_count . " words\n"
			. "Text must feel natural and human.\n"
			. "Avoid repetition, vague generalities, and AI clichés.\n"
			. "Keep paragraphs short.\n"
			. "Use examples, tables, lists, checklists, and comparisons when useful.\n";

		if ( $seo_focus ) {
			$prompt .= "\nPrimary keyword:\n"
				. "{topic}\n\n"
				. "Use it naturally in:\n"
				. "Title\n"
				. "Meta description\n"
				. "Introduction\n"
				. "At least one H2\n"
				. "Several H3s\n"
				. "Conclusion\n\n"
				. "Also use related semantic keywords naturally.\n";
		}

		$prompt .= "\nOutput structure\n"
			. "Section order:\n"
			. implode( "\n", $sections ) . "\n"
			. "Organize content with H2 and H3 headings and use the following when needed:\n\n"
			. implode( "\n", $body_blocks ) . "\n";

		if ( $include_faq ) {
			$prompt .= "8 to 12 frequently asked questions with complete, practical answers.\n";
		}

		if ( $include_conclusion ) {
			$prompt .= "Conclusion\n"
				. "Summarize the most important points and encourage the reader to apply the content.\n";
		}

		if ( '' !== trim( $notes ) ) {
			$prompt .= "\nAdditional notes:\n" . $notes . "\n";
		}

		$prompt .= "\nBefore submitting, verify:\n"
			. "- Fully unique\n"
			. "- SEO-optimized\n"
			. "- Natural, fluent writing\n"
			. "- Real value for the reader\n"
			. "- No repetitive text or cliché sentences\n"
			. "- Return only the article with no extra commentary.";

		return $prompt;
	}

	/**
	 * @param string $tone Tone key.
	 */
	private static function get_prompt_tone_label_fa( string $tone ): string {
		$labels = array(
			'professional' => 'حرفه‌ای، آموزشی و دوستانه',
			'friendly'     => 'دوستانه و صمیمی',
			'formal'       => 'رسمی و رسمیت‌محور',
			'educational'  => 'آموزشی و گام‌به‌گام',
			'news'         => 'خبری و بی‌طرف',
			'persuasive'   => 'متقاعدکننده و الهام‌بخش',
		);

		return $labels[ $tone ] ?? $labels['professional'];
	}

	/**
	 * @param string $tone Tone key.
	 */
	private static function get_prompt_tone_label_en( string $tone ): string {
		$labels = array(
			'professional' => 'professional, educational, and friendly',
			'friendly'     => 'friendly and approachable',
			'formal'       => 'formal and authoritative',
			'educational'  => 'educational and step-by-step',
			'news'         => 'news-style and neutral',
			'persuasive'   => 'persuasive and inspiring',
		);

		return $labels[ $tone ] ?? $labels['professional'];
	}

	/**
	 * Generate an article prompt template from builder preferences (not saved).
	 *
	 * @param array<string,mixed> $args Builder form values.
	 * @return string Prompt template text.
	 */
	public static function generate_prompt_from_builder( array $args ): string {
		return self::compose_prompt_template_from_preferences( $args );
	}
}
