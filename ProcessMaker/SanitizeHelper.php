<?php
namespace ProcessMaker;

use Illuminate\Support\Facades\Validator;
use ProcessMaker\Models\ProcessRequestToken;
use ProcessMaker\Models\Screen;
use function GuzzleHttp\json_decode;

class SanitizeHelper {
    /**
     * The tags that should always be sanitized, even
     * when the controller specifies doNotSanitize
     *
     * @var array
     */
    private static $blacklist = [
        '<form>',
        '<input>',
        '<textarea>',
        '<button>',
        '<select>',
        '<option>',
        '<optgroup>',
        '<fieldset>',
        '<label>',
        '<output>',
    ];

    /**
     * Sanitize the given value.
     *
     * @param  string  $key
     * @param  mixed  $value
     * @param  boolean $strip_tags
     * @return mixed
     */
    public static function sanitize($value, $strip_tags = true)
    {
        if (is_string($value) && $strip_tags) {
            // Remove most injectable code
            $value = strip_tags($value);
            $value = self::sanitizeVueExp($value);

            // Return the sanitized string
            return $value;
        } elseif (is_string($value)) {
            // Remove tags in blacklist, even if $strip_tags is false
            foreach (self::$blacklist as $tag) {
                $regexp = self::convertTagToRegExp($tag);
                $value = preg_replace($regexp, '', $value);
            }
            return $value;
        }

        // Return the original value.
        return $value;
    }

    /**
     * Convert a <tag> into a regexp.
     *
     * @param string $tag
     *
     * @return string
     */
    private static function convertTagToRegExp($tag)
    {
        return '/' . str_replace(['\<', '\>'], ['<[\s\/]*', '[^>]*>'], preg_quote($tag)) . '/i';
    }

    /**
     * Sanitize each element of an array. Do not sanitize rich text elements
     *
     * @param string $tag
     *
     * @return string
     */
    public static function sanitizeData($data, $screen)
    {
        $except = self::getExceptions($screen);
        if (isset($data['_DO_NOT_SANITIZE'])) {
           $except = array_unique(array_merge(json_decode($data['_DO_NOT_SANITIZE']), $except));
        }
        $data['_DO_NOT_SANITIZE'] = json_encode($except);
        return self::sanitizeWithExceptions($data, $except);
    }

    private static function sanitizeWithExceptions(Array $data, Array $except, $level = 0)
    {
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $data[$key] = self::sanitizeWithExceptions($value, $except, $level + 1);
            } else {
                // Only allow skipping on top-level data for now
                $strip_tags = $level !== 0 || !in_array($key, $except);
                $data[$key] = self::sanitize($value, $strip_tags);
            }
        }
        return $data;
    }

    private static function getExceptions($screen)
    {
        $except = [];
        if (!$screen) {
            return $except;
        }
        $config = $screen->config;
        foreach ($config as $page) {
            if (isset($page['items']) && is_array($page['items'])) {
                $except = array_merge($except, self::getRichTextElements($page['items']));
            }
        }
        return $except;
    }

    private static function getRichTextElements($items)
    {
        $elements = [];
        foreach ($items as $item) {
            if (isset($item['items']) && is_array($item['items'])) {
                // Inside a table
                foreach ($item['items'] as $cell) {
                    if (is_array($cell)) {
                        $elements = array_merge($elements, self::getRichTextElements($cell));
                    }
                }
            } else {
                if (
                    isset($item['component']) &&
                    $item['component'] === 'FormTextArea' &&
                    isset($item['config']['richtext']) &&
                    $item['config']['richtext'] === true
                ) {
                    $elements[] = $item['config']['name'];
                }
            }
        }
        return $elements;
    }

    public static function sanitizeEmail($email)
    {
        $validator = Validator::make(['email' => $email], [
            'email'=>'required|email'
        ]);
        if ($validator->fails()) {
            return '';
        } else {
            return $email;
        }
    }

    public static function sanitizePhoneNumber($number)
    {
        $regexp = "/^[+]*[(]{0,1}[0-9]{1,4}[)]{0,1}[-\s\.\/0-9]*$/";
        if (preg_match($regexp, $number)) {
            return $number;
        } else {
            return '';
        }
    }

    public static function sanitizeVueExp($string)
    {
        // strip {{, }}
        $codes = [
            '{{' => '',
            '}}' => '',
        ];
        return str_replace(array_keys($codes), array_values($codes), $string);
    }
}
