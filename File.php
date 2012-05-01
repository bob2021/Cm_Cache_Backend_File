<?php
/**
 * Cm_Cache_Backend_File
 *

==New BSD License==

Copyright (c) 2012, Colin Mollenhour
All rights reserved.

Redistribution and use in source and binary forms, with or without
modification, are permitted provided that the following conditions are met:

    * Redistributions of source code must retain the above copyright
      notice, this list of conditions and the following disclaimer.
    * Redistributions in binary form must reproduce the above copyright
      notice, this list of conditions and the following disclaimer in the
      documentation and/or other materials provided with the distribution.
    * The name of Colin Mollenhour may not be used to endorse or promote products
      derived from this software without specific prior written permission.

THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS "AS IS" AND
ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT LIMITED TO, THE IMPLIED
WARRANTIES OF MERCHANTABILITY AND FITNESS FOR A PARTICULAR PURPOSE ARE
DISCLAIMED. IN NO EVENT SHALL <COPYRIGHT HOLDER> BE LIABLE FOR ANY
DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES
(INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES;
LOSS OF USE, DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER CAUSED AND
ON ANY THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY, OR TORT
(INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE OF THIS
SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGE.
 */
class Cm_Cache_Backend_File extends Zend_Cache_Backend_File
{
    /**
     * Available options
     *
     * =====> (string) cache_dir :
     * - Directory where to put the cache files
     *
     * =====> (boolean) file_locking :
     * - Enable / disable file_locking
     * - Can avoid cache corruption under bad circumstances but it doesn't work on multithread
     * webservers and on NFS filesystems for example
     *
     * =====> (boolean) read_control :
     * - Enable / disable read control
     * - If enabled, a control key is embeded in cache file and this key is compared with the one
     * calculated after the reading.
     *
     * =====> (string) read_control_type :
     * - Type of read control (only if read control is enabled). Available values are :
     *   'md5' for a md5 hash control (best but slowest)
     *   'crc32' for a crc32 hash control (lightly less safe but faster, better choice)
     *   'adler32' for an adler32 hash control (excellent choice too, faster than crc32)
     *   'strlen' for a length only test (fastest)
     *
     * =====> (int) hashed_directory_level :
     * - Hashed directory level
     * - Set the hashed directory structure level. 0 means "no hashed directory
     * structure", 1 means "one level of directory", 2 means "two levels"...
     * This option can speed up the cache only when you have many thousands of
     * cache file. Only specific benchs can help you to choose the perfect value
     * for you. Maybe, 1 or 2 is a good start.
     *
     * =====> (int) hashed_directory_umask :
     * - Umask for hashed directory structure
     *
     * =====> (string) file_name_prefix :
     * - prefix for cache files
     * - be really carefull with this option because a too generic value in a system cache dir
     *   (like /tmp) can cause disasters when cleaning the cache
     *
     * =====> (int) cache_file_umask :
     * - Umask for cache files
     *
     * @var array available options
     */
    protected $_options = array(
        'cache_dir' => null,
        'file_locking' => true,
        'read_control' => false,
        'read_control_type' => 'crc32',
        'hashed_directory_level' => 2,
        'hashed_directory_umask' => 0770,
        'file_name_prefix' => 'cm',
        'cache_file_umask' => 0660,
        'metadatas_array_max_size' => 100
    );

    protected $isTagDir;

    /**
     * @param array $options
     */
    public function __construct(array $options = array())
    {
        if (empty($options['cache_dir']) && class_exists('Mage', false)) {
            $options['cache_dir'] = Mage::getBaseDir('cache');
        }
        parent::__construct($options);
    }

    /**
     * Test if a cache is available for the given id and (if yes) return it (false else)
     *
     * @param string $id cache id
     * @param boolean $doNotTestCacheValidity if set to true, the cache validity won't be tested
     * @return string|bool cached datas
     */
    public function load($id, $doNotTestCacheValidity = false)
    {
        $file = $this->_file($id);
        $cache = $this->_getCache($file, true);
        if ( ! $cache) {
            return false;
        }
        list($metadatas, $data) = $cache;
        if ( ! $doNotTestCacheValidity && (time() > $metadatas['expire'])) {
            // ?? $this->remove($id);
            return false;
        }
        if ($this->_options['read_control']) {
            $hashData = $this->_hash($data, $this->_options['read_control_type']);
            $hashControl = $metadatas['hash'];
            if ($hashData != $hashControl) {
                // Problem detected by the read control !
                $this->_log('Zend_Cache_Backend_File::load() / read_control : stored hash and computed hash do not match');
                $this->remove($id);
                return false;
            }
        }
        return $data;
    }

    /**
     * Save some string datas into a cache record
     *
     * Note : $data is always "string" (serialization is done by the
     * core not by the backend)
     *
     * @param  string $data             Datas to cache
     * @param  string $id               Cache id
     * @param  array  $tags             Array of strings, the cache record will be tagged by each string entry
     * @param  bool|int $specificLifetime If != false, set a specific lifetime for this cache record (null => infinite lifetime)
     * @return boolean true if no problem
     */
    public function save($data, $id, $tags = array(), $specificLifetime = false)
    {
        $file = $this->_file($id);
        $path = $this->_path($id);
        if ($this->_options['hashed_directory_level'] > 0) {
            if (!is_writable($path)) {
                // maybe, we just have to build the directory structure
                $this->_recursiveMkdirAndChmod($id);
            }
            if (!is_writable($path)) {
                return false;
            }
        }
        if ($this->_options['read_control']) {
            $hash = $this->_hash($data, $this->_options['read_control_type']);
        } else {
            $hash = '';
        }
        $metadatas = array(
            'hash' => $hash,
            'mtime' => time(),
            'expire' => $this->_expireTime($this->getLifetime($specificLifetime)),
            'tags' => implode(',', $tags),
        );
        $res = $this->_filePutContents($file, serialize($metadatas)."\n".$data);
        $res = $res && $this->_updateIdsTags(array($id), $tags, 'merge');
        return $res;
    }

    /**
     * Remove a cache record
     *
     * @param  string $id cache id
     * @return boolean true if no problem
     */
    public function remove($id)
    {
        $file = $this->_file($id);
        $metadatas = $this->_getCache($file, false);
        if ($metadatas) {
            $boolRemove   = $this->_remove($file);
            $boolTags     = $this->_updateIdsTags(array($id), explode(',', $metadatas['tags']), 'diff');
            return $boolRemove && $boolTags;
        }
        return false;
    }

    /**
     * Clean some cache records
     *
     * Available modes are :
     * 'all' (default)  => remove all cache entries ($tags is not used)
     * 'old'            => remove too old cache entries ($tags is not used)
     * 'matchingTag'    => remove cache entries matching all given tags
     *                     ($tags can be an array of strings or a single string)
     * 'notMatchingTag' => remove cache entries not matching one of the given tags
     *                     ($tags can be an array of strings or a single string)
     * 'matchingAnyTag' => remove cache entries matching any given tags
     *                     ($tags can be an array of strings or a single string)
     *
     * @param string $mode
     * @param array $tags
     * @return boolean true if no problem
     */
    public function clean($mode = Zend_Cache::CLEANING_MODE_ALL, $tags = array())
    {
        // We use this protected method to hide the recursive stuff
        clearstatcache();
        switch($mode) {
            case Zend_Cache::CLEANING_MODE_ALL:
            case Zend_Cache::CLEANING_MODE_OLD:
                return $this->_clean($this->_options['cache_dir'], $mode);
            default:
                return $this->_cleanNew($mode, $tags);
        }
    }

    /**
     * Return an array of stored tags
     *
     * @return array array of stored tags (string)
     */
    public function getTags()
    {
        $prefix = $this->_tagFile('');
        $prefixLen = strlen($prefix);
        $tags = array();
        foreach (@glob($prefix . '*') as $tagFile) {
            $tags[] = substr($tagFile, $prefixLen);
        }
        return $tags;
    }

    /**
     * Return an array of stored cache ids which match given tags
     *
     * In case of multiple tags, a logical AND is made between tags
     *
     * @param array $tags array of tags
     * @return array array of matching cache ids (string)
     */
    public function getIdsMatchingTags($tags = array())
    {
        return $this->_getIdsByTags(Zend_Cache::CLEANING_MODE_MATCHING_TAG, $tags);
    }

    /**
     * Return an array of stored cache ids which don't match given tags
     *
     * In case of multiple tags, a logical OR is made between tags
     *
     * @param array $tags array of tags
     * @return array array of not matching cache ids (string)
     */
    public function getIdsNotMatchingTags($tags = array())
    {
        return $this->_getIdsByTags(Zend_Cache::CLEANING_MODE_NOT_MATCHING_TAG, $tags);
    }

    /**
     * Return an array of stored cache ids which match any given tags
     *
     * In case of multiple tags, a logical AND is made between tags
     *
     * @param array $tags array of tags
     * @return array array of any matching cache ids (string)
     */
    public function getIdsMatchingAnyTags($tags = array())
    {
        return $this->_getIdsByTags(Zend_Cache::CLEANING_MODE_MATCHING_ANY_TAG, $tags);
    }

    /**
     * Return an array of metadatas for the given cache id
     *
     * The array must include these keys :
     * - expire : the expire timestamp
     * - tags : a string array of tags
     * - mtime : timestamp of last modification time
     *
     * @param string $id cache id
     * @return array array of metadatas (false if the cache id is not found)
     */
    public function getMetadatas($id)
    {
        $metadatas = $this->_getCache($this->_file($id), false);
        if ($metadatas) {
            $metadatas['tags'] = explode(',' ,$metadatas['tags']);
        }
        return $metadatas;
    }

    /**
     * Give (if possible) an extra lifetime to the given cache id
     *
     * @param string $id cache id
     * @param int $extraLifetime
     * @return boolean true if ok
     */
    public function touch($id, $extraLifetime)
    {
        $file = $this->_file($id);
        $cache = $this->_getCache($file, true);
        if (!$cache) {
            return false;
        }
        list($metadatas, $data) = $cache;
        if (time() > $metadatas['expire']) {
            return false;
        }
        $newMetadatas = array(
            'hash' => $metadatas['hash'],
            'mtime' => time(),
            'expire' => $metadatas['expire'] + $extraLifetime,
            'tags' => $metadatas['tags']
        );
        return !! $this->_filePutContents($file, serialize($newMetadatas)."\n".$data);
    }

    /**
     * Get a metadatas record and optionally the data as well
     *
     * @param  string $file  Cache file
     * @param  bool $withData
     * @return array|bool
     */
    protected function _getCache($file, $withData)
    {
        if (!is_file($file) || ! ($fd = @fopen($file, 'rb'))) {
            return false;
        }
        if ($this->_options['file_locking']) flock($fd, LOCK_SH);
        $metadata = fgets($fd);
        if ( ! $metadata) {
            fclose($fd);
            return false;
        }
        if ($withData) {
            $data = stream_get_contents($fd);
        }
        fclose($fd);
        $metadata = @unserialize(rtrim($metadata,"\n"));
        if ($withData) {
            return array($metadata, $data);
        }
        return $metadata;
    }

    /**
     * Get a metadatas record
     *
     * @param  string $id  Cache id
     * @return array|bool Associative array of metadatas
     */
    protected function _getMetadatas($id)
    {
        return $this->_getCache($this->_file($id), false);
    }

    /**
     * Set a metadatas record
     *
     * @param  string $id        Cache id
     * @param  array  $metadatas Associative array of metadatas
     * @param  boolean $save     optional pass false to disable saving to file
     * @return boolean True if no problem
     */
    protected function _setMetadatas($id, $metadatas, $save = true)
    {
        // TODO - implement for unit tests ___expire method
        return true;
    }

    /**
     * Return the complete directory path of a filename (including hashedDirectoryStructure)
     *
     * Uses multiple letters for a single-level hash rather than multiple levels
     *
     * @param  string $id Cache id
     * @param  boolean $parts if true, returns array of directory parts instead of single string
     * @return string Complete directory path
     */
    protected function _path($id, $parts = false)
    {
        $partsArray = array();
        $root = $this->_options['cache_dir'];
        $prefix = $this->_options['file_name_prefix'];
        if ($this->_options['hashed_directory_level']>0) {
            $hash = hash('adler32', $id);
            $root = $root . $prefix . '--' . substr($hash, -$this->_options['hashed_directory_level']) . DIRECTORY_SEPARATOR;
            $partsArray[] = $root;
        }
        if ($parts) {
            return $partsArray;
        } else {
            return $root;
        }
    }

    /**
     * Clean some cache records (protected method used for recursive stuff)
     *
     * Available modes are :
     * Zend_Cache::CLEANING_MODE_ALL (default)    => remove all cache entries ($tags is not used)
     * Zend_Cache::CLEANING_MODE_OLD              => remove too old cache entries ($tags is not used)
     * Zend_Cache::CLEANING_MODE_MATCHING_TAG     => remove cache entries matching all given tags
     *                                               ($tags can be an array of strings or a single string)
     * Zend_Cache::CLEANING_MODE_NOT_MATCHING_TAG => remove cache entries not {matching one of the given tags}
     *                                               ($tags can be an array of strings or a single string)
     * Zend_Cache::CLEANING_MODE_MATCHING_ANY_TAG => remove cache entries matching any given tags
     *                                               ($tags can be an array of strings or a single string)
     *
     * @param string $dir  Directory to clean
     * @param string $mode Clean mode
     * @param array $tags
     * @throws Zend_Cache_Exception
     * @return boolean True if no problem
     */
    protected function _clean($dir, $mode = Zend_Cache::CLEANING_MODE_ALL, $tags = array())
    {
        if (!is_dir($dir)) {
            return false;
        }
        $result = true;
        $glob = @glob($dir . $this->_options['file_name_prefix'] . '--*');
        if ($glob === false) {
            return true;
        }
        foreach ($glob as $file)  {
            if (is_file($file)) {
                switch ($mode) {
                    case Zend_Cache::CLEANING_MODE_ALL:
                        $result = @unlink($file) && $result;
                        continue;
                }

                $id = $this->_fileNameToId(basename($file));
                $_file = $this->_file($id);
                if ($file != $_file) {
                    @unlink($file);
                    continue;
                }
                $metadatas = $this->_getCache($file, false);
                if ( ! $metadatas) {
                    @unlink($file);
                    continue;
                }
                switch ($mode) {
                    case Zend_Cache::CLEANING_MODE_OLD:
                        if (time() > $metadatas['expire']) {
                            $result = $this->_remove($file) && $result;
                            $result = $this->_updateIdsTags(array($id), explode(',', $metadatas['tags']), 'diff') && $result;
                        }
                        continue;
                    default:
                        Zend_Cache::throwException('Invalid mode for clean() method');
                        break;
                }
            }
            if ((is_dir($file)) and ($this->_options['hashed_directory_level']>0)) {
                // Recursive call
                $result = $this->_clean($file . DIRECTORY_SEPARATOR, $mode) && $result;
                if ($mode == 'all') {
                    // if mode=='all', we try to drop the structure too
                    @rmdir($file);
                }
            }
        }
        if ($mode == 'all') {
            foreach (glob($this->_tagFile('*')) as $tagFile) {
                @unlink($tagFile);
            }
        }
        return $result;
    }

    /**
     * Clean some cache records (protected method used for recursive stuff)
     *
     * Available modes are :
     * Zend_Cache::CLEANING_MODE_ALL (default)    => remove all cache entries ($tags is not used)
     * Zend_Cache::CLEANING_MODE_OLD              => remove too old cache entries ($tags is not used)
     * Zend_Cache::CLEANING_MODE_MATCHING_TAG     => remove cache entries matching all given tags
     *                                               ($tags can be an array of strings or a single string)
     * Zend_Cache::CLEANING_MODE_NOT_MATCHING_TAG => remove cache entries not {matching one of the given tags}
     *                                               ($tags can be an array of strings or a single string)
     * Zend_Cache::CLEANING_MODE_MATCHING_ANY_TAG => remove cache entries matching any given tags
     *                                               ($tags can be an array of strings or a single string)
     *
     * @param  string $mode Clean mode
     * @param  array  $tags Array of tags
     * @throws Zend_Cache_Exception
     * @return boolean True if no problem
     */
    protected function _cleanNew($mode = Zend_Cache::CLEANING_MODE_ALL, $tags = array())
    {
        $result = true;
        $ids = $this->_getIdsByTags($mode, $tags);
        foreach ($ids as $id) {
            $idFile = $this->_file($id);
            if (is_file($idFile)) {
                $result = $result && $this->_remove($idFile);
            }
        }
        switch($mode)
        {
            case Zend_Cache::CLEANING_MODE_MATCHING_ANY_TAG:
                foreach ($tags as $tag) {
                    $tagFile = $this->_tagFile($tag);
                    if (is_file($tagFile)) {
                        $result = $result && $this->_remove($tagFile);
                    }
                }
                break;
            case Zend_Cache::CLEANING_MODE_NOT_MATCHING_TAG:
            case Zend_Cache::CLEANING_MODE_MATCHING_TAG:
                $this->_updateIdsTags($ids, $tags, 'diff');
                break;
        }
        return $result;
    }

    /**
     * @param string $mode
     * @param array $tags
     * @return array
     */
    protected function _getIdsByTags($mode, $tags)
    {
        $ids = array();
        switch($mode) {
            case Zend_Cache::CLEANING_MODE_NOT_MATCHING_TAG:
                $ids = $this->getIds();
                if ($tags) {
                    foreach ($tags as $tag) {
                        if ( ! $ids) {
                            break; // early termination optimization
                        }
                        $ids = array_diff($ids, $this->_getTagIds($tag));
                    }
                }
                break;
            case Zend_Cache::CLEANING_MODE_MATCHING_TAG:
                if ($tags) {
                    $tag = array_shift($tags);
                    $ids = $this->_getTagIds($tag);
                    foreach ($tags as $tag) {
                        if ( ! $ids) {
                            break; // early termination optimization
                        }
                        $ids = array_intersect($ids, $this->_getTagIds($tag));
                    }
                    $ids = array_unique($ids);
                }
                break;
            case Zend_Cache::CLEANING_MODE_MATCHING_ANY_TAG:
                foreach ($tags as $tag) {
                    $ids = $ids + $this->_getTagIds($tag);
                }
                $ids = array_unique($ids);
                break;
        }
        return $ids;
    }

    /**
     * Make and return a file name (with path)
     *
     * @param  string $id Cache id
     * @return string File name (with path)
     */
    protected function _tagFile($id)
    {
        $path = $this->_tagPath();
        $fileName = $this->_idToFileName($id);
        return $path . $fileName;
    }

    /**
     * Return the complete directory path where tags are stored
     *
     * @return string Complete directory path
     */
    protected function _tagPath()
    {
        $path = $this->_options['cache_dir'] . DIRECTORY_SEPARATOR . 'tags' . DIRECTORY_SEPARATOR;
        if ( ! $this->isTagDir) {
            if ( ! is_dir($path)) {
                @mkdir($path, $this->_options['hashed_directory_umask']);
                @chmod($path, $this->_options['hashed_directory_umask']); // see #ZF-320 (this line is required in some configurations)
            }
            $this->isTagDir = true;
        }
        return $path;
    }

    /**
     * @param string|resource $tag
     * @return array
     */
    protected function _getTagIds($tag)
    {
        if (is_resource($tag)) {
            $ids = stream_get_contents($tag);
        } else {
            $ids = @file_get_contents($this->_tagFile($tag));
        }
        if( ! $ids) {
            return array();
        }
        $ids = substr($ids, 0, strrpos($ids, "\n"));
        return explode("\n", $ids);
    }

    /**
     * @param array $ids
     * @param array $tags
     * @param string $mode
     * @return bool
     */
    protected function _updateIdsTags($ids, $tags, $mode)
    {
        $result = true;
        foreach($tags as $tag) {
            $file = $this->_tagFile($tag);
            if (file_exists($file)) {
                if ( ! $ids && $mode == 'diff') {
                    $result = $this->_remove($file);
                }
                else if ($mode == 'diff' || (rand(1,100) == 1 && filesize($file) > 4096)) {
                    $file = $this->_tagFile($tag);
                    if ( ! ($fd = fopen($file, 'rb+'))) {
                        $result = false;
                        continue;
                    }
                    if ($this->_options['file_locking']) flock($fd, LOCK_EX);
                    if ($mode == 'diff') {
                        $_ids = array_diff($this->_getTagIds($fd), $ids);
                    } else { // if ($mode == 'merge')
                        $_ids = $this->_getTagIds($fd) + $ids;
                    }
                    fseek($fd, 0);
                    ftruncate($fd, 0);
                    $result = fwrite($fd, implode("\n", array_unique($_ids))."\n") && $result;
                    fclose($fd);
                }
                else {
                    $result = file_put_contents($file, implode("\n", $ids)."\n", FILE_APPEND | ($this->_options['file_locking'] ? LOCK_EX : 0)) && $result;
                }
            } else if ($mode == 'merge') {
                $result = $this->_filePutContents($file, implode("\n", $ids)."\n") && $result;
            }
        }
        return $result;
    }

    /**
     * Put the given string into the given file
     *
     * @param  string $file   File complete path
     * @param  string $string String to put in file
     * @return boolean true if no problem
     */
    protected function _filePutContents($file, $string)
    {
        $result = @file_put_contents($file, $string, $this->_options['file_locking'] ? LOCK_EX : 0);
        $result && chmod($file, $this->_options['cache_file_umask']);
        return $result;
    }

    /**
     * For unit testing only
     * @param $id
     */
    public function ___expire($id)
    {
        $metadata = $this->_getMetadatas($id);
        $this->touch($id, 1 - $metadata['expire']);
    }

}
