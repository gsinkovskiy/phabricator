<?php

final class AphrontFormSelectControl extends AphrontFormControl {

  protected function getCustomControlClass() {
    return 'aphront-form-control-select';
  }

  private $options;
  private $disabledOptions = array();
  private $multiple = false;

  public function setOptions(array $options) {
    $this->options = $options;
    return $this;
  }

  public function getOptions() {
    return $this->options;
  }

  public function setDisabledOptions(array $disabled) {
    $this->disabledOptions = $disabled;
    return $this;
  }

  public function setMultiple($multiple) {
    $this->multiple = $multiple;
    return $this;
  }

  public function readValueFromRequest(AphrontRequest $request) {
    if (!$this->multiple) {
      return parent::readValueFromRequest($request);
    }

    $this->setValue($request->getArr($this->getName()));
    return $this;
  }

  public function setValue($value) {
    if ($this->multiple && !is_array($value)) {
      throw new Exception('Multi-select value must be an array');
    }

    return parent::setValue($value);
  }

  protected function renderInput() {
    return self::renderSelectTag(
      $this->getValue(),
      $this->getOptions(),
      array(
        'name'      => $this->getName().($this->multiple ? '[]' : ''),
        'disabled'  => $this->getDisabled() ? 'disabled' : null,
        'multiple'  => $this->multiple ? 'multiple' : null,
        'id'        => $this->getID(),
      ),
      $this->disabledOptions);
  }

  public static function renderSelectTag(
    $selected,
    array $options,
    array $attrs = array(),
    array $disabled = array()) {

    $option_tags = self::renderOptions($selected, $options, $disabled);

    return javelin_tag(
      'select',
      $attrs,
      $option_tags);
  }

  private static function renderOptions(
    $selected,
    array $options,
    array $disabled = array()) {
    $disabled = array_fuse($disabled);
    $selected = (array)$selected;

    $tags = array();
    foreach ($options as $value => $thing) {
      if (is_array($thing)) {
        $tags[] = phutil_tag(
          'optgroup',
          array(
            'label' => $value,
          ),
          self::renderOptions($selected, $thing));
      } else {
        $tags[] = phutil_tag(
          'option',
          array(
            'selected' => in_array($value, $selected) ? 'selected' : null,
            'value'    => $value,
            'disabled' => isset($disabled[$value]) ? 'disabled' : null,
          ),
          $thing);
      }
    }
    return $tags;
  }

}
