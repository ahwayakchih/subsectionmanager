<?php
//	ini_set("display_errors","2");
//	ERROR_REPORTING(E_ALL);

	/**
	 * @package fields
	 */
	/**
	 * This field provides access to entries that point to its entry. 
	 */
	if(!defined('__IN_SYMPHONY__')) die('<h2>Symphony Error</h2><p>You cannot directly access this file</p>');

	Class fieldMirrorSectionAssociates extends Field {

		static $sectionIDsByName;
		static $sectionFieldIDsByName;
		static $fieldIDsByName;

		// content.publish.php does not want us to use array for single search value, so we store them here and return index key.
		static $searchValues;

		/**
		 * Construct a new instance of this field.
		 *
		 * @param mixed $parent
		 *  The class that created this Field object, usually the FieldManager,
		 *  passed by reference.
		 */
		public function __construct(&$parent){
			parent::__construct($parent);

			$this->_name = __('Mirror Section Associates');
			$this->_required = true;
			$this->_showassociation = true;

			// Set default
			$this->set('required', 'yes');
			$this->set('show_column', 'no');
			$this->set('hide', 'yes');
			$this->set('show_association', 'yes');

			// Prepare information about sections
			if (empty(self::$sectionIDsByName)) {
				$sectionManager = new SectionManager(Symphony::Engine());
			  	$sections = $sectionManager->fetch(NULL, 'ASC', 'name');
				foreach ($sections as $section) {
					self::$sectionIDsByName[$section->get('handle')] = $section->get('id');
					$fields = $section->fetchFields('subsectionmanager');
					if (is_array($fields)) {
						foreach ($fields as $field) {
							self::$sectionFieldIDsByName[$section->get('handle')][strtolower($field->get('element_name'))] = $field->get('id');
							self::$fieldIDsByName[strtolower($field->get('element_name'))][] = $field->get('id');
						}
					}
				}
			}
		}

		/**
		 * Test whether this field can be filtered. This default implementation
		 * prohibits filtering. Filtering allows the xml output results to be limited
		 * according to an input parameter. Subclasses should override this if
		 * filtering is supported.
		 *
		 * @return boolean
		 *	true if this can be filtered, false otherwise.
		 */
		public function canFilter(){
			return true;
		}

		/**
		 * Test whether this field can be prepopulated with data. This default
		 * implementation does not support pre-population and, thus, returns false.
		 *
		 * @return boolean
		 *	true if this can be pre-populated, false otherwise.
		 */
		public function canPrePopulate(){
			return false;
// TODO: implement prepopulation support by setting hidden fields on entry edit page!
		}

		/**
		 * Test whether this field requires grouping. This default implementation
		 * returns false.
		 *
		 * @return boolean
		 *	true if this field requires grouping, false otherwise.
		 */
		public function requiresSQLGrouping(){
			// There will be many entries pointing to this entry, so we need grouping by ID
			return true;
		}

		/**
		 * Test whether this field supports data-source parameter output. This
		 * default implementation prohibits parameter output. Data-source
		 * parameter output allows this field to be provided as a parameter
		 * to other data-sources or XSLT. Subclasses should override this if
		 * parameter output is supported.
		 *
		 * @return boolean
		 *	true if this supports data-source parameter output, false otherwise.
		 */
		public function allowDatasourceParamOutput(){
			return true;
		}

		/**
		 * Display the default settings panel, calls the `buildSummaryBlock`
		 * function after basic field settings are added to the wrapper.
		 *
		 * @see buildSummaryBlock()
		 * @param XMLElement $wrapper
		 *	the input XMLElement to which the display of this will be appended.
		 * @param mixed errors (optional)
		 *	the input error collection. this defaults to null.
		 */
		public function displaySettingsPanel(XMLElement &$wrapper, $errors = null){
			parent::displaySettingsPanel($wrapper, $errors);

			// Get current section id
			$section_id = Symphony::Engine()->Page->_context[1];

			// Related section
			$label = new XMLElement('label', __('Display following parents in entries table'));
			$sectionManager = new SectionManager(Symphony::Engine());
		  	$sections = $sectionManager->fetch(NULL, 'ASC', 'name');
			$options = array(
				array('', false, __('None Selected')),
			);
			if(is_array($sections) && !empty($sections)) {
				$parents = $this->fetchParentSectionsFieldIDs();
				foreach($sections as $section) {
					$id = $section->get('id');
					$fields = $section->fetchFields('subsectionmanager');
					if (is_array($fields)) {
						foreach($fields as $field){
							if ($field->get('subsection_id') == $section_id)
								$options[$id]['options'][] = array($field->get('id'), (in_array($field->get('id'), $parents)), $field->get('label'));
						}
					}
					if(empty($options[$id]['options'])) unset($options[$id]);
					else $options[$id]['label'] = $section->get('name');
				}
			}

			$label->appendChild(Widget::Select('fields[' . $this->get('sortorder') . '][parent_section_field_id][]', $options, array('class' => 'mirrorsectionassociates', 'multiple' => 'multiple')));
			if(isset($errors['parent_section_field_id'])) {
				$wrapper->appendChild(Widget::wrapFormElementWithError($label, $errors['parent_section_field_id']));
			}
			else {
				$wrapper->appendChild($label);
			}

			$this->appendShowAssociationCheckbox($wrapper);
			$this->appendShowColumnCheckbox($wrapper);
		}

		/**
		 * Check the field's settings to ensure they are valid on the section
		 * editor
		 *
		 * @param array $errors
		 *	the array to populate with the errors found.
		 * @param boolean $checkForDuplicates (optional)
		 *	if set to true, duplicate field entries will be flagged as errors.
		 *	this defaults to true.
		 * @return integer
		 *	returns the status of the checking. if errors has been populated with
		 *	any errors `self::__ERROR__`, `self::__OK__` otherwise.
		 */
		public function checkFields(Array &$errors, $checkForDuplicates = true) {
			$fieldManager = new FieldManager(Symphony::Engine());
			$fields = $fieldManager->fetch(null, null, 'ASC', 'sortorder', 'subsectionmanager');
			$parents = $this->fetchParentSectionsFieldIDs();

			$ids = array();
			foreach ($fields as $field) {
				$ids[] = $field->get('id');
			}
			$diff = array_diff($parents, $ids);
			if (!empty($diff)) {
				$errors['parent_section_field_id'] = __('Some of the selected fields are not valid subsectionmanager fields.');
				return self::__ERROR__;
			}

			return parent::checkFields($errors, $checkForDuplicates);
		}

		/**
		 * Format this field value for display in the publish index tables.
		 *
		 * @param array $data
		 *	an associative array of data for this string. At minimum this requires a
		 *  key of 'value'.
		 * @param XMLElement $link (optional)
		 *	an xml link structure to append the content of this to provided it is not
		 *	null. it defaults to null.
		 * @return string
		 *	the formatted string summary of the values of this field instance.
		 */
		public function prepareTableValue($data, XMLElement $link = null) {
			if(!is_array($data) || (is_array($data) && empty($data['value']))) return parent::prepareTableValue(NULL);

			static $fm = NULL;
			static $fields = array();
			if (empty($fm)) {
				$fm = new FieldManager(Symphony::Engine());
			}

			static $primaries = array();
			static $values = array();

			$output = '';
			$result = $this->getParameterPoolValue($data);
			if (empty($result)) return parent::prepareTableValue(NULL);

			$entries = Symphony::Database()->fetch("SELECT DISTINCT(`e`.`id`) as `entry_id`, `e`.`section_id` FROM `tbl_entries` as `e` WHERE `e`.`id` IN ({$result})");
			foreach ($entries as $entry) {
				$entry_id = $entry['entry_id'];

				if (empty($primaries[$entry['section_id']])) {
					$primaries[$entry['section_id']] = Symphony::Database()->fetchRow(0, "SELECT f.`id`, f.`parent_section` AS `section_id`, `s`.`handle` as `section_handle` FROM `tbl_fields` `f` LEFT JOIN `tbl_sections` `s` ON `f`.`parent_section` = `s`.`id` WHERE `f`.`parent_section` = {$entry['section_id']} ORDER BY `f`.`sortorder` ASC LIMIT 1");
				}
				$field = $primaries[$entry['section_id']];

				if (empty($field)) continue;

				$field_id = $field['id'];
				if (!isset($fields[$field_id])) {
					$fields[$field_id] = $fm->fetch($field_id);
				}

				if (empty($fields[$field_id])) continue;

				// Get value
				if (!isset($values[$entry_id][$field_id])) {
					$values[$entry_id][$field_id] = '';
					$data = Symphony::Database()->fetchRow(0, "SELECT * FROM tbl_entries_data_{$field_id} WHERE entry_id = {$entry_id} ORDER BY id DESC LIMIT 1");
					if(is_callable(array($fields[$field_id], 'preparePlainTextValue'))) {
						$field_value = $values[$entry_id][$field_id] = $fields[$field_id]->preparePlainTextValue($data, $entry_id);
					}
					else {
						$field_value = $values[$entry_id][$field_id] = strip_tags($fields[$field_id]->prepareTableValue($data));
					}
				}
				else {
					$field_value = $values[$entry_id][$field_id];
				}

				$link = Widget::Anchor($field_value, sprintf('%s/symphony/publish/%s/edit/%d/', URL, $field['section_handle'], $entry_id));
				$output .= $link->generate() . ' ';
			}

			return $output;
/*
			static $fm = NULL;
			static $sm = NULL;
			static $sections = array();
			static $fields = array();
			if (empty($fm)) {
				$fm = new FieldManager(Symphony::Engine());
				$sm = new SectionManager(Symphony::Engine());
			}

			$parents = $this->fetchParentSectionsFieldIDs();
			// TODO: separate it by fields and just show count per each field. Linked to filtered section index.
			$links = array();
			$entries = array();
			foreach ($parents as $field_id) {
				$value = Symphony::Database()->fetchCol('entry_id', "SELECT DISTINCT `e`.`id` as `entry_id` FROM `tbl_entries` as `e` INNER JOIN `tbl_entries_data_{$field_id}` AS `f` ON `f`.`entry_id` = `e`.`id` WHERE `f`.`relation_id` = {$data['value']}");
				if (!empty($value)) {
					if (!isset($fields[$field_id])) {
						$fields[$field_id] = $fm->fetch($field_id);
					}
					if (!empty($fields[$field_id])) {
						$section_id = $fields[$field_id]->get('parent_section');
						if (!isset($sections[$section_id])) {
							$sections[$section_id] = $sm->fetch($section_id);
						}
						if (!empty($sections[$section_id])) {
							if (!is_array($links[$section_id])) {
								$links[$section_id] = array();
								$entries[$section_id] = array();
							}
							$link = Widget::Anchor(__('%d %s of %s', array(count($value), $fields[$field_id]->get('label'), $sections[$section_id]->get('name'))), sprintf('%s/symphony/publish/%s?filter=%s:%d', URL, $sections[$section_id]->get('handle'), $fields[$field_id]->get('element_name'), $data['value']));
							$links[$section_id][$field_id] = $link->generate();
							$entries[$section_id] = array_merge($entries[$section_id], $value);
						}
					}
				}
			}

			$container = new XMLElement('ul');
			$container->setAttribute('class', 'mirrorsectionassociates');
			foreach ($links as $section_id => $f) {
				$s = count(array_unique($entries[$section_id]));
				if ($s < 1) continue;

				$item = new XMLElement('li');

				$span = new XMLElement('span', __('%d in %s', array($s, $sections[$section_id]->get('name'))));
				$item->appendChild($span);

				$ul = new XMLElement('ul', '<li>'.implode('</li><li>', $links[$section_id]).'</li>', array('style' => 'display: none;'));
				$item->appendChild($ul);

				$container->appendChild($item);
			}

			static $css_done = false;
			if (!$css_done) {
// TODO: add stylesheet to head
			}

			return $container->generate();
*/
		}

		/**
		 * Display the publish panel for this field. The display panel is the
		 * interface to create the data in instances of this field once added
		 * to a section.
		 *
		 * @param XMLElement $wrapper
		 *	the xml element to append the html defined user interface to this
		 *	field.
		 * @param array $data (optional)
		 *	any existing data that has been supplied for this field instance.
		 *	this is encoded as an array of columns, each column maps to an
		 *	array of row indexes to the contents of that column. this defaults
		 *	to null.
		 * @param mixed $flagWithError (optional)
		 *	flag with error defaults to null.
		 * @param string $fieldnamePrefix (optional)
		 *	the string to be prepended to the display of the name of this field.
		 *	this defaults to null.
		 * @param string $fieldnameSuffix (optional)
		 *	the string to be appended to the display of the name of this field.
		 *	this defaults to null.
		 * @param number $entry_id (optional)
		 *	the entry id of this field. this defaults to null.
		 */
		public function displayPublishPanel(XMLElement &$wrapper, $data = null, $flagWithError = null, $fieldnamePrefix = null, $fieldnamePostfix = null, $entry_id = null){

			$parents = $this->fetchParentSectionsFieldIDs();
			$entries = array();
			foreach ($parents as $field_id) {
				$value = $this->fetchAssociatedEntrySearchValue(array('relation_id' => $data['value']), $field_id);
				$entries = array_merge($entries, $this->fetchAssociatedEntryIDs($value));
			}

//			$input = Widget::Input('fields[mirrorsectionassociates_ids][' . $this->get('id') . ']', $this->get('subsection_id'), 'hidden');
//			$wrapper->appendChild($input);
		}

		/**
		 * Process the raw field data.
		 *
		 * @param mixed $data
		 *	post data from the entry form
		 * @param reference $status
		 *	the status code resultant from processing the data.
		 * @param boolean $simulate (optional)
		 *	true if this will tell the CF's to simulate data creation, false
		 *	otherwise. this defaults to false. this is important if clients
		 *	will be deleting or adding data outside of the main entry object
		 *	commit function.
		 * @param mixed $entry_id (optional)
		 *	the current entry. defaults to null.
		 * @return array
		 *	the processed field data.
		 */
		public function processRawFieldData($data, &$status, $simulate=false, $entry_id=null) {
			$status = self::__OK__;

			if (!$simulate && !empty($entry_id) && !empty($_REQUEST['prepopulate']) && ($value = $_REQUEST['prepopulate'][$this->get('id')])) {
				if (!is_array($value)) $value = array($value);
				$parents = $this->fetchParentSectionsFieldIDs();
				foreach ($value as $v) {
					list($section, $field, $parent_id) = explode('.', $v);
					if (!empty(self::$sectionFieldIDsByName[$section][$field])) {
						if (in_array(self::$sectionFieldIDsByName[$section][$field], $parents)) {
							// TODO: go through whole Symphony process of editing and saving entry?
							// TODO2: Save data only AFTER entry was saved (so we can be sure we will not create invalid associations)?
							//		Maybe by setting up some static/global variable, and inserting data from it at exit?
							$fields = array();
							$fields['entry_id'] = $parent_id;
							$fields['relation_id'] = $entry_id;
							$settings = Symphony::Database()->insert($fields, 'tbl_entries_data_'.self::$sectionFieldIDsByName[$section][$field]);
						}
					}
				}
			}

			return array(
				// We have to store $entry_id, because prepareTableValue does not get it from caller or in $data :(
				'value' => $entry_id,
			);
		}

		/**
		 * Display the default data-source filter panel.
		 *
		 * @param XMLElement $wrapper
		 *	the input XMLElement to which the display of this will be appended.
		 * @param mixed $data (optional)
		 *	the input data. this defaults to null.
		 * @param mixed errors (optional)
		 *	the input error collection. this defaults to null.
		 * @param string $fieldNamePrefix
		 *	the prefix to apply to the display of this.
		 * @param string $fieldNameSuffix
		 *	the suffix to apply to the display of this.
		 */
		public function displayDatasourceFilterPanel(XMLElement &$wrapper, $data = null, $errors = null, $fieldnamePrefix = null, $fieldnamePostfix = null){
			parent::displayDatasourceFilterPanel($wrapper, $data, $errors, $fieldnamePrefix, $fieldnamePostfix);
			//$text = new XMLElement('p', __('Use comma separated entry ids for filtering.'), array('class' => 'help') );
			//$wrapper->appendChild($text);
		}

		/**
		 * Construct the SQL statement fragments to use to retrieve the data of this
		 * field when utilized as a data source.
		 *
		 * @param array $data
		 *	the supplied form data to use to construct the query from
		 * @param string $joins
		 *	the join sql statement fragment to append the additional join sql to.
		 * @param string $where
		 *	the where condition sql statement fragment to which the additional
		 *	where conditions will be appended.
		 * @param boolean $andOperation (optional)
		 *	true if the values of the input data should be appended as part of
		 *	the where condition. this defaults to false.
		 * @return boolean
		 *	true if the construction of the sql was successful, false otherwise.
		 */
		public function buildDSRetrivalSQL($data, &$joins, &$where, $andOperation = false) {
			// Selects by parent entry IDs
			// Supports following PATH syntax:
			// - section_name:field_name:entry_id
			// - section_name:field_name
			// - section_name:*:entry_id
			// - section_name:*
			// - *:field_name:entry_id
			// - *:field_name
			// - *:*:entry_id
			// - entry_id
			// - *:*:*
			// - *:*
			// - *
			// Last three work the same way and are equivalent to an empty filter
			// value passed as section_name should be possible to find with $section->get('handle')
			// value passed as field_name should be possible to find with $field->get('element_name')


			if (!is_array($data)) $data = array($data);
			$main_key = ++$this->_key;
			$j = $w = array();
			foreach ($data as $value) {
				$this->parseSectionFieldEntriesFilter($value, $j, $w, $main_key);
			}

			if (empty($j) || empty($w)) return true;

			$id = $this->get('id');
			$joins .= "
				LEFT JOIN
					`tbl_entries_data_{$id}` AS `t{$id}_{$main_key}`
					ON (`e`.`id` = `t{$id}_{$main_key}`.`entry_id`)
			".implode('', $j);
			$where .= "
				AND (
					`t{$id}_{$main_key}`.`entry_id` IS NOT NULL
					and (
						".implode(($andOperation ? ' and ' : ' OR '), $w)."
					)
				)
			";

			return true;
		}

		/**
		 * Function to format this field if it chosen in a data-source to be
		 * output as a parameter in the XML
		 *
		 * @param array $data
		 *	 The data for this field from it's `tbl_entry_data_{id}` table
		 * @return string
		 *	 The formatted value to be used as the parameter
		 */
		public function getParameterPoolValue(Array $data){
			if(!is_array($data) || (is_array($data) && empty($data['value']))) return array();

			$parents = $this->fetchParentSectionsFieldIDs();
			$entries = array();
			foreach ($parents as $field_id) {
				$value = Symphony::Database()->fetchCol('entry_id', "SELECT DISTINCT `e`.`id` as `entry_id` FROM `tbl_entries` as `e` INNER JOIN `tbl_entries_data_{$field_id}` AS `f` ON `f`.`entry_id` = `e`.`id` WHERE `f`.`relation_id` = {$data['value']}");
				$entries = array_merge($entries, $value);
			}
			return implode(',', array_unique($entries));
		}

		/**
		 * Append the formatted xml output of this field as utilized as a data source.
		 *
		 * @param XMLElement $wrapper
		 *	the xml element to append the xml representation of this to.
		 * @param array $data
		 *	the current set of values for this field. the values are structured as
		 *	for displayPublishPanel.
		 * @param boolean $encode (optional)
		 *	flag as to whether this should be html encoded prior to output. this
		 *	defaults to false.
		 * @param string $mode
		 *	 A field can provide ways to output this field's data. For instance a mode
		 *  could be 'items' or 'full' and then the function would display the data
		 *  in a different way depending on what was selected in the datasource
		 *  included elements.
		 * @param number $entry_id (optional)
		 *	the identifier of this field entry instance. defaults to null.
		 */
		public function appendFormattedElement(XMLElement &$wrapper, $data, $encode = false, $mode = null, $entry_id = null) {
			$container = new XMLElement($this->get('element_name'));

			static $fm = NULL;
			static $fields = array();
			if (empty($fm)) {
				$fm = new FieldManager(Symphony::Engine());
			}

			static $primaries = array();

			$output = '';
			$result = $this->getParameterPoolValue($data);

			if (!empty($result)) {
				$entries = Symphony::Database()->fetch("SELECT DISTINCT(`e`.`id`) as `entry_id`, `e`.`section_id` FROM `tbl_entries` as `e` WHERE `e`.`id` IN ({$result})");
				foreach ($entries as $entry) {
					if (empty($primaries[$entry['section_id']])) {
						$primaries[$entry['section_id']] = Symphony::Database()->fetchRow(0, "SELECT f.`id`, f.`parent_section` AS `section_id`, `s`.`handle` as `section_handle` FROM `tbl_fields` `f` LEFT JOIN `tbl_sections` `s` ON `f`.`parent_section` = `s`.`id` WHERE `f`.`parent_section` = {$entry['section_id']} ORDER BY `f`.`sortorder` ASC LIMIT 1");
					}
					$field = $primaries[$entry['section_id']];

					if (empty($field)) continue;

					if (!isset($fields[$field['id']])) {
						$fields[$field['id']] = $fm->fetch($field['id']);
					}

					if (empty($fields[$field['id']])) continue;
					$data = Symphony::Database()->fetchRow(0, "SELECT * FROM tbl_entries_data_{$field['id']} WHERE entry_id = {$entry['entry_id']} ORDER BY id DESC LIMIT 1");

					$item = new XMLElement('item');
					$item->setAttribute('id', $data['entry_id']);
					$item->setAttribute('section-id', $entry['section_id']);
					if (!empty($data['handle'])) $item->setAttribute('handle', $data['handle']);

					$fields[$field['id']]->appendFormattedElement($item, $data, false, NULL);

					$container->appendChild($item);
				}
			}

			$wrapper->appendChild($container);
		}

		/**
		 * The default method for constructing the example form markup containing this
		 * field when utilized as part of an event. This displays in the event documentation
		 * and serves as a basic guide for how markup should be constructed on the
		 * Frontend to save this field
		 *
		 * @return XMLElement
		 *	a label widget containing the formatted field element name of this.
		 */
		public function getExampleFormMarkup(){
			return new XMLElement('div');
		}

		/**
		 * Commit the settings of this field from the section editor to
		 * create an instance of this field in a section.
		 *
		 * @return boolean
		 *	true if the commit was successful, false otherwise.
		 */
		public function commit(){
/*
			Symphony::Database()->query(
				"DROP TABLE IF EXISTS `tbl_fields_mirrorsectionassociates`"
			);
*/
			Symphony::Database()->query(
				"CREATE TABLE IF NOT EXISTS `tbl_fields_mirrorsectionassociates` (
					`id` int(11) unsigned NOT NULL AUTO_INCREMENT,
					`field_id` int(11) unsigned NOT NULL,
					`show_association` enum('yes','no') DEFAULT 'no',
			  		PRIMARY KEY  (`id`),
			  		KEY `field_id` (`field_id`)
				)"
			);

			// Prepare commit
			if(!parent::commit()) return false;

			// The $id is set only after parent::commit()
			$id = $this->get('id');
			if($id === false) return false;

			$fields = array();
			$fields['field_id'] = $id;
			$fields['show_association'] = ($this->get('show_association') == 'yes' ? 'yes' : 'no');

			Symphony::Database()->query("DELETE FROM `tbl_fields_".$this->handle()."` WHERE `field_id` = '{$id}'");
			if (!Symphony::Database()->insert($fields, 'tbl_fields_' . $this->handle())) return false;

			// Remove old secion association
			$this->removeSectionAssociation($id);

			// Save new section association
			$show_column = ($this->get('show_association') == 'yes');
			foreach ($this->get('parent_section_field_id') as $parent_field_id) {
				if (!$this->createSectionAssociation(NULL, $id, $parent_field_id, $show_column)) {
					return false;
				}
			}

			return true;
		}

		/**
		 * The default field table construction method. This constructs the bare
		 * minimum set of columns for a valid field table. Subclasses are expected
		 * to overload this method to create a table structure that contains
		 * additional columns to store the specific data created by the field.
		 */
		public function createTable(){
			$result = Symphony::Database()->query(
				"CREATE TABLE IF NOT EXISTS `tbl_entries_data_" . $this->get('id') . "` (
				  `id` int(11) unsigned NOT NULL auto_increment,
				  `entry_id` int(11) unsigned NOT NULL,
				  `value` int(11) unsigned NOT NULL,
				  PRIMARY KEY  (`id`),
				  KEY `entry_id` (`entry_id`),
				  KEY `value` (`value`)
				) ENGINE=MyISAM;"
			);

			if ($result) {
				Symphony::Database()->query("
					INSERT INTO `tbl_entries_data_" . $this->get('id') . "` (`entry_id`, `value`)
						SELECT `id`, `id` FROM `tbl_entries` WHERE `section_id` = " . $this->get('parent_section') . "
				");
			}

			return $result;
		}

		/**
		 * Accessor to the associated entry search value for this field
		 * instance. This default implementation simply returns the input
		 * data argument.
		 *
		 * @param array $data
		 *	the data from which to construct the associated search entry value.
		 * @param number $field_id (optional)
		 *	an optional id of the associated field? this defaults to null.
		 * @param number $parent_entry_id (optional)
		 *	an optional parent identifier of the associated field entry? this defaults
		 *	to null.
		 * @return array
		 *	the associated entry search value. this implementation returns the input
		 *	data argument.
		 */
		public function fetchAssociatedEntrySearchValue($data, $field_id = null, $parent_entry_id = null){
			$result = array(
				'section' => '*',
				'field' => '*',
				'entry' => '*',
			);

			if (!empty($parent_entry_id)) {
				$result['entry'] = $parent_entry_id;
			}
			else if (!is_array($data)) {
				$result['entry'] = $data;
			}

			if (!empty($field_id)) {
				foreach (self::$sectionFieldIDsByName as $section => $fields) {
					foreach ($fields as $field => $id) {
						if ($id == $field_id) {
							$result['section'] = $section;
							$result['field'] = $field;
							break 2;
						}
					}
				}
			}

			if ($result['entry'] == '*' && !empty($data['relation_id']) && !empty($field_id)) {
				if (!is_array($data['relation_id'])) $data['relation_id'] = array($data['relation_id']);
				$parent_entry_id = Symphony::Database()->fetchRow(0, sprintf("
					SELECT `entry_id` FROM `tbl_entries_data_%d`
					WHERE `relation_id` IN (%s)
					LIMIT 1", $field_id, implode(',', array_map('intval', $data['relation_id']))
				));
				if (!empty($parent_entry_id)) $result['entry'] = implode(',', $parent_entry_id);
			}

			return implode('.', $result);
		}

		/**
		 * Fetch the count of the associate entries for the input value. This default
		 * implementation does nothing.
		 *
		 * @param mixed $value
		 *	the value to find the associated entry count for.
		 * @return void|integer
		 *	this default implementation returns void. overriding implementations should
		 *	return a number.
		 */
		public function fetchAssociatedEntryCount($value){
			if (empty($value)) return array();

			$join = $where = '';
			$result = $this->buildDSRetrivalSQL($value, $join, $where, false);
			if (!$result) return array();

			return Symphony::Database()->fetchVar('count', 0, "SELECT COUNT(DISTINCT `e`.`id`) as `count` FROM `tbl_entries` as `e` {$join} WHERE 1 {$where}");
		}

		/**
		 * Accessor to the ids associated with this field instance.
		 *
		 * @param mixed $value
		 *	the value to find the associated entry ids for.
		 * @return void|array
		 *	this default implementation returns void. overriding implementations should
		 *	return an array of the associated entry ids.
		 */
		public function fetchAssociatedEntryIDs($value){
			if (empty($value)) return array();
			$join = $where = '';
			$result = $this->buildDSRetrivalSQL($value, $join, $where, false);
			if (!$result) return array();

			return Symphony::Database()->fetchCol('entry_id', "SELECT DISTINCT `e`.`id` as `entry_id` FROM `tbl_entries` as `e` {$join} WHERE 1 {$where}");
		}



		public function fetchParentSectionsFieldIDs(){
			$parents = $this->get('parent_section_field_id');
			if (empty($parents) && !is_array($parents)) {
				// Get current "parents"
				$parents = Symphony::Database()->fetchCol('parent_section_field_id', sprintf("
							SELECT parent_section_field_id
							FROM `tbl_sections_association` AS `sa`, `tbl_sections` AS `s`
							WHERE `sa`.`child_section_field_id` = %d
							AND `s`.`id` = `sa`.`child_section_id`
							ORDER BY `s`.`sortorder` ASC
						",
						$this->get('id')
					)
				);
				$this->set('parent_section_field_id', $parents);
			}
			return $parents;
		}

		public function parseSectionFieldEntriesFilter($filter, &$join, &$where, $key) {
			$fields = array();

			list($section, $field, $entry_id, $reversed) = explode('.', $filter);

			// Only two were arguments passed?
			// section.field or section.entry_id
			if (empty($entry_id)) {
				// One value passed?
				// section or entry_id
				if (empty($field)) {
					// Single value means it is entry_id or section name
					if (is_numeric($section)) {
						$entry_id = $section;
						$section = $field = NULL;
					}
				}
				// Two values passed, first is section name, second may be field name or entry_id
				else {
					// Second value is entry_id
					if (is_numeric($field)) {
						$entry_id = $field;
						$field = NULL;
					}
					else {
					}
				}
			}
			else {
			}

			$entry_id = explode('|', $entry_id);
			$entry_id = array_map('intval', $entry_id);
			$entry_id = implode(',', $entry_id);

			$id = $this->get('id');
			$parents = $this->fetchParentSectionsFieldIDs();

			$joinCol = ($reversed ? 'entry_id' : 'relation_id');
			$whereCol = ($reversed ? 'relation_id' : 'entry_id');

			if ((empty($section) || $section == '*') && (empty($field) || $field == '*')) {
				if (!empty($entry_id)) {
					$j = $w = array();
					foreach ($parents as $field_id) {
						$this->_key++;

						$j[] =  "
							LEFT JOIN
								`tbl_entries_data_{$field_id}` AS `t{$field_id}_{$this->_key}`
								ON (`t{$field_id}_{$this->_key}`.`{$joinCol}` = `t{$id}_{$key}`.`entry_id`)
						";
						$w[] = "
							`t{$field_id}_{$this->_key}`.`{$whereCol}` IN ({$entry_id})
						";
					}
					if (!empty($j)) {
						$join[] = implode($j);
						$where[] = '('.implode(' OR ', $w).')';
					}
				}
				return;
			}

			if ((empty($section) || $section == '*') && !empty(self::$fieldIDsByName[$field])) {
				$j = $w = array();
				foreach (array_intersect(self::$fieldIDsByName[$field], $parents) as $field_id) {
					$this->_key++;
					$j[] =  "
						LEFT JOIN
							`tbl_entries_data_{$field_id}` AS `t{$field_id}_{$this->_key}`
							ON (`t{$field_id}_{$this->_key}`.`{$joinCol}` = `t{$id}_{$key}`.`entry_id`)
					";
					$w[] = "
						`t{$field_id}_{$this->_key}`.`{$whereCol}` ".(empty($entry_id) ? 'IS NOT NULL' : " IN ({$entry_id})")."
					";
				}
				if (!empty($j)) {
					$join[] = implode($j);
					$where[] = '('.implode(' OR ', $w).')';
				}
			}
			else if (!empty(self::$sectionIDsByName[$section])) {
				if (empty($field) || $field == '*') {
					foreach (array_intersect(self::$sectionFieldIDsByName[$section], $parents) as $field_id) {
						$this->_key++;
						$j[] =  "
							LEFT JOIN
								`tbl_entries_data_{$field_id}` AS `t{$field_id}_{$this->_key}`
								ON (`t{$field_id}_{$this->_key}`.`{$joinCol}` = `t{$id}_{$key}`.`entry_id`)
						";
						$w[] = "
							`t{$field_id}_{$this->_key}`.`{$whereCol}` ".(empty($entry_id) ? 'IS NOT NULL' : " IN ({$entry_id})")."
						";
					}
					if (!empty($j)) {
						$join[] = implode($j);
						$where[] = '('.implode(' OR ', $w).')';
					}
				}
				else if (!empty(self::$sectionFieldIDsByName[$section][$field])) {
					$field_id = self::$sectionFieldIDsByName[$section][$field];
					if (in_array($field_id, $parents)) {
						$this->_key++;
						$join[] =  "
							LEFT JOIN
								`tbl_entries_data_{$field_id}` AS `t{$field_id}_{$this->_key}`
								ON (`t{$field_id}_{$this->_key}`.`{$joinCol}` = `t{$id}_{$key}`.`entry_id`)
						";
						$where[] = "
							`t{$field_id}_{$this->_key}`.`{$whereCol}` ".(empty($entry_id) ? 'IS NOT NULL' : " IN ({$entry_id})")."
						";
					}
				}
			}

			return $fields;
		}
	}
