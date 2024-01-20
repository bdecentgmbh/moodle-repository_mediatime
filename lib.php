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
 * This plugin is Media Time repository
 *
 * @package    repository_mediatime
 * @copyright  2024 bdecent gmbh <https://bdecent.de>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/repository/lib.php');
require_once($CFG->dirroot . '/lib/resourcelib.php');

use tool_mediatime\output\media_resource;
use tool_mediatime\plugininfo\mediatimesrc;

/**
 * repository_mediatime class is used to share Media Time resources
 *
 * @copyright  2024 bdecent gmbh <https://bdecent.de>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Media Time repository class
 *
 * @copyright  2024 bdecent gmbh <https://bdecent.de>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class repository_mediatime extends repository {

    /**
     * Initialize repositorymediatime plugin
     * @param int $repositoryid
     * @param int $context
     * @param array $options
     */
    public function __construct($repositoryid, $context = SYSCONTEXTID, $options = []) {
        $this->context = $context;

        parent::__construct($repositoryid, $context, $options);
    }

    /**
     * Media Time plugin doesn't require login, so list all files
     * @return mixed
     */
    public function print_login() {
        return $this->get_listing();
    }

    /**
     * Get a list of links
     *
     * @param string $path this parameter can a folder name, or a identification of folder
     * @param string $page the page number of file list
     * @return array
     */
    public function get_listing($path = '', $page = '') {
        $manageurl = new moodle_url('/admin/tool/mediatime/index.php', ['repository' => $this->id]);

        return [
            'manage' => $manageurl->out(),
            'nologin' => true,
            'nosearch' => true,
            'list' => $this->get_mediatime_resources(),
        ];
    }

    /**
     * Only return resouces usable in context
     *
     * @return array list of mediatime files
     */
    private function get_mediatime_resources(): array {
        global $USER, $DB, $OUTPUT;

        $result = [];
        if (!$sources = mediatimesrc::get_enabled_plugins()) {
            return $result;
        }
        list($sql, $params) = $DB->get_in_or_equal($sources);
        $rs = $DB->get_recordset_select(
            'tool_mediatime',
            "source $sql",
            $params,
            'timecreated DESC'
        );
        foreach ($rs as $record) {
            $record->content = json_decode($record->content);
            $resource = new media_resource($record);
            $url = new moodle_url('/admin/tool/mediatime/index.php', ['id' => $record->id]);
            $imageurl = $resource->image_url($OUTPUT);
            $videourl = $resource->video_url($OUTPUT);
            $result[] = [
                'title' => $record->content->title . '.' . resourcelib_get_extension($videourl),
                'shorttitle' => $record->content->title,
                'thumbnail' => $imageurl,
                'realicon' => $imageurl,
                'datemodified' => (int)$record->timecreated,
                'datecreated' => (int)$record->timecreated,
                'url' => $url,
                'source' => $record->id,
                'author' => fullname(core_user::get_user($record->usermodified)),
            ];
        }
        return $result;
        $rs->close();
    }

    /**
     * Get Media Time resource renderable from tool
     *
     * @param int $source
     * @return \tool_mediatime\output\media_resource
     */
    protected function get_resource(int $source) {
        global $DB;

        $record = $DB->get_record('tool_mediatime', ['id' => $source]);
        $record->content = json_decode($record->content);
        return new media_resource($record);
    }

    /**
     * Get download link
     *
     * @param int $source
     * @return string URL
     */
    public function get_link($source) {
        global $OUTPUT;

        $resource = $this->get_resource($source);
        return $resource->video_url($OUTPUT);
    }

    /**
     * Downloads a file from external repository and saves it in temp dir
     *
     * @param string $reference the content of files.reference field
     * @param string $saveas filename (without path) to save the downloaded file in the
     * temporary directory
     * @return array with elements:
     *   path: internal location of the file
     *   url: URL to the source (from parameters)
     */
    public function get_file($reference, $saveas = '') {
        global $OUTPUT;

        $saveas = $this->prepare_file($saveas);
        $resource = $this->get_resource($reference);
        file_put_contents($saveas, $resource->video_file_content($OUTPUT));

        return [
            'path' => $saveas,
            'url' => $resource->video_url($OUTPUT),
        ];
    }

    /**
     * Prepare file reference information
     *
     * @param string $source source of the file
     * @return string file reference, ready to be stored
     */
    public function get_file_reference($source) {
        return (int)$source;
    }

    /**
     * Return names of the general options.
     * By default: no general option name
     *
     * @return array
     */
    public static function get_type_option_names() {
        return [
            'mediatimefilesnumber',
            'mediatimefilestimelimit', 'pluginname',
        ];
    }

    /**
     * This plugin doesn't support to link to external links
     *
     * @return int
     */
    public function supported_returntypes() {
        if (!empty($this->get_option('externalfile'))) {
            $returntypes = FILE_EXTERNAL;
        } else {
            $returntypes = 0;
        }
        if (!empty($this->get_option('internalfile'))) {
            $returntypes |= FILE_INTERNAL;
        }
        if (!empty($this->get_option('filereference'))) {
            $returntypes |= FILE_REFERENCE;
        }
        if (empty($returntypes)) {
            return FILE_EXTERNAL | FILE_INTERNAL | FILE_REFERENCE;
        }
        return $returntypes;
    }

    /**
     * Repository method to make sure that user can access particular file.
     *
     * This is checked when user tries to pick the file from repository to deal with
     * potential parameter substitutions is request
     *
     * @todo MDL-33805 remove this function when mediatime files are managed correctly
     *
     * @param string $source
     * @return bool whether the file is accessible by current user
     */
    public function file_is_accessible($source) {
        global $USER;
        return true;
        $reference = $this->get_file_reference($source);
        $file = self::get_moodle_file($reference);
        return (!empty($file) && $file->get_userid() == $USER->id);
    }

    /**
     * Does this repository used to browse moodle files?
     *
     * @return boolean
     */
    public function has_moodle_files() {
        return false;
    }

    /**
     * Is this repository accessing private data?
     *
     * @return bool
     */
    public function contains_private_data() {
        return false;
    }

    /**
     * Return names of the instance options.
     *
     * @return array
     */
    public static function get_instance_option_names() {
        return [
            'externalfile',
            'internalfile',
            'filereference',
        ];
    }

    /**
     * Save settings for repository instance
     *
     * @param array $options settings
     * @return bool
     */
    public function set_option($options = []) {
        $options['externalfile'] = clean_param($options['externalfile'], PARAM_INT);
        $options['internalfile'] = clean_param($options['internalfile'], PARAM_INT);
        $options['filereference'] = clean_param($options['filereference'], PARAM_INT);
        $ret = parent::set_option($options);
        return $ret;
    }

    /**
     * Edit/Create Instance Settings Moodle form
     *
     * @param moodleform $mform Moodle form (passed by reference)
     */
    public static function instance_config_form($mform) {
        global $CFG;
        if (has_capability('moodle/site:config', context_system::instance())) {
            $mform->addElement(
                'checkbox',
                'externalfile',
                get_string('returntypes', 'repository_mediatime'),
                get_string('externalfile', 'repository_mediatime')
            );
            $mform->setType('externalfile', PARAM_INT);
            $mform->setDefault('externalfile', 1);
            $mform->addElement(
                'checkbox',
                'internalfile',
                '',
                get_string('internalfile', 'repository_mediatime')
            );
            $mform->setType('internalfile', PARAM_INT);
            $mform->addElement(
                'checkbox',
                'filereference',
                '',
                get_string('filereference', 'repository_mediatime')
            );
            $mform->setType('filereference', PARAM_INT);
            $mform->setDefault('filereference', 1);

        } else {
            $mform->addElement('static', null, '',  get_string('nopermissions', 'error', get_string('configplugin',
                'repository_mediatime')));
            return false;
        }
    }

    /**
     * Create an instance for this plug-in
     *
     * @param string $type the type of the repository
     * @param int $userid the user id
     * @param stdClass $context the context
     * @param array $params the options for this instance
     * @param int $readonly whether to create it readonly or not (defaults to not)
     * @return mixed
     */
    public static function create($type, $userid, $context, $params, $readonly=0) {
        if (has_capability('moodle/site:config', context_system::instance())) {
            return parent::create($type, $userid, $context, $params, $readonly);
        } else {
            require_capability('moodle/site:config', context_system::instance());
            return false;
        }
    }

    /**
     * Validate repository plugin instance form
     *
     * @param moodleform $mform moodle form
     * @param array $data form data
     * @param array $errors errors
     * @return array errors
     */
    public static function instance_form_validation($mform, $data, $errors) {
        if (
            empty(clean_param($data['externalfile'] ?? 0,  PARAM_INT))
            && empty(clean_param($data['internalfile'] ?? 0, PARAM_INT))
            && empty(clean_param($data['filereference'] ?? 0, PARAM_INT))
        ) {
            $errors['filereference'] = get_string('selectreturntype', 'repository_mediatime');
        }
        return $errors;
    }

    /**
     * Repository method to serve the referenced file
     *
     * @see send_stored_file
     *
     * @param stored_file $storedfile the file that contains the reference
     * @param int $lifetime Number of seconds before the file should expire from caches (null means $CFG->filelifetime)
     * @param int $filter 0 (default)=no filtering, 1=all files, 2=html files only
     * @param bool $forcedownload If true (default false), forces download of file rather than view in browser/plugin
     * @param array $options additional options affecting the file serving
     */
    public function send_file($storedfile, $lifetime=null , $filter=0, $forcedownload=false, array $options = null) {
        $reference = $storedfile->get_reference();
        if (!$resource = $this->get_resource($reference)) {
            send_file_not_found();
        }
        [
            'path' => $file,
            'url' => $url,
        ]  = $this->get_file($reference);
        $path = explode('/', $url);
        $filename = end($path);

        send_file($file, $filename, $lifetime , $filter, false, $forcedownload, '', $dontdie);
    }
}
