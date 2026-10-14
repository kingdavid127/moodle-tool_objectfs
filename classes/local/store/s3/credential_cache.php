<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Cross-request cache for the credentials the AWS SDK resolves on its own.
 *
 * @package   tool_objectfs
 * @author    David Saylor
 * @copyright Catalyst IT
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace tool_objectfs\local\store\s3;

/**
 * Shares the credentials the SDK resolves (instance profile, ECS task role, SSO)
 * across PHP requests through a Moodle application cache.
 *
 * Without a cache the SDK memoises resolved credentials per process only, so under
 * PHP-FPM every request that touches S3 first repeats the metadata-service round
 * trips (IMDSv2 token, role name, credentials). The SDK stores the Credentials object
 * together with its remaining lifetime and checks isExpired() on whatever it gets
 * back, so this cache only has to keep the value and drop it once that lifetime has
 * passed.
 *
 * The entry holds a temporary access key, secret and session token. The application
 * cache lives under the site's cache directory with the same permissions as the rest
 * of Moodle's cached data; the setting s3_cachesdkcreds turns this off for sites that
 * do not want that.
 */
class credential_cache implements \Aws\CacheInterface {
    /** @var \cache_application */
    private $cache;

    /**
     * Opens the application cache the credentials live in.
     */
    public function __construct() {
        $this->cache = \cache::make_from_params(
            \cache_store::MODE_APPLICATION,
            'tool_objectfs',
            'sdkcredentials',
            [],
            ['simplekeys' => true, 'simpledata' => false]
        );
    }

    /**
     * Returns the cached value for the key, or null when there is none or it has expired.
     *
     * @param string $key
     * @return mixed|null
     */
    public function get($key) {
        $entry = $this->cache->get($key);
        if (!is_array($entry) || !array_key_exists('value', $entry)) {
            return null;
        }
        if (!empty($entry['expires']) && $entry['expires'] <= time()) {
            $this->cache->delete($key);
            return null;
        }
        return $entry['value'];
    }

    /**
     * Stores a value, remembering when it stops being valid.
     *
     * @param string $key
     * @param mixed $value
     * @param int $ttl seconds the value stays valid; 0 means no expiry
     * @return void
     */
    public function set($key, $value, $ttl = 0) {
        $this->cache->set($key, [
            'value' => $value,
            'expires' => $ttl > 0 ? time() + (int) $ttl : 0,
        ]);
    }

    /**
     * Drops a cached value.
     *
     * @param string $key
     * @return void
     */
    public function remove($key) {
        $this->cache->delete($key);
    }
}
