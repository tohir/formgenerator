<?php

if (!defined('FORM_SALT')){
    define('FORM_SALT', 'oJ2l5yaDMLPiVwg9eZCMzWcLkrp1k');
}

// Forms are valid for 6 minutes! This mainly affects people who refresh
if (!defined('FORM_TIMEOUT')){
    define('FORM_TIMEOUT', 360);
}

// This is the location where file uploads are stored - must have trailing slash
if (!defined('TMP_UPLOAD_LOCATION')) {
    define('TMP_UPLOAD_LOCATION', '/tmp/tmpuploads/');
}

class FormGenerator
{
    private $formParts;
    private $elements;
    private $formId = '';
    private $elementLayout;
    
    private $renderedElements = array();
    
    private $validationRules = array();
    private $validationMessages = array();
 	
    private $addOnJavascript = array();
    
    private $postMode = FALSE;
    private $nonceValid = TRUE;
    private $formData = array();
    private $isValid = FALSE;
    
    private $validationErrors = array();
    
    
    /**
     * @param array $structure Form Structure and Attributes
     * @param string $elementLayout Layout in which elements are rendered
     *      Needs string template with [-LABEL-] and [-ELEMENT-] - Like <div class="row"> [-LABEL-] : [-ELEMENT-] [-EXTRA-]</div>
     * @param boolean $postMode True if we etting the submitted values and validating
     */
    public function __construct($structure, $elementLayout='', $postMode=FALSE)
    {
        // Defaults for the Form Structure
        $defaultFormStructure = array(
            '__form_id' => uniqid('form_'),
            'name' => 'form',
            'method' => 'post',
            'action' => '',
            'class' => '', // CSS
            'submitbutton' => 'Save Form',
            'elements' => array(),
            'form_before_elements_html' => '',
            'form_after_elements_html' => '',
        );
        
        $structure = array_merge($defaultFormStructure, $structure);
        
        // Grab Form Id, store separately
        $this->formId = $structure['__form_id'];
        unset($structure['__form_id']);
        
        // Generate Nonce Key
        $this->nonceKey = \TohirExternal\NonceUtil::generate(FORM_SALT.'~'.$this->formId, FORM_TIMEOUT);
        
        // Store Elements separately and remove from Structure
        $this->elements = $structure['elements'];
        unset($structure['elements']);
        
        // Force all forms to use post method? Implications?
        $structure['method'] = 'post';
        
        $this->formParts = $structure;
        
        if (empty($elementLayout)) {
            $elementLayout = <<<Layout
<div class="row">
    <div class="label">[-LABEL-] </div>
    <div class="element">[-ELEMENT-] [-EXTRA-]</div>
    <br clear="both" />
</div>
Layout;
        }
        
        $this->elementLayout = $elementLayout;
        
        $this->postMode = $postMode;
        
        // IF Post Mode is On, Valid is Turned On, and will FAIL when one of the items fails.
        if ($this->postMode) {
            $this->isValid = TRUE;
        }
        
        $this->prepare();
    }
    
    /**
     * This loops through the form elements and generates them as well as validation
     * In the case of postMode, it also checks the validation
     */
    private function prepare()
    {
        // Create an anonymous function to pass through to element classes
        $parent = $this;
        $anonFindElement = function($elementName) use ($parent)
        {
            return $parent->findElement($elementName);
        };
        
        
        // Added Hidden Form Id
        $hiddenFormId = new FormElement_Hidden('__form_id', array('value'=>$this->formId), $anonFindElement, $this->postMode);
        
        // Create Nonce Element
        $nonceElement = new FormElement_Hidden('__nonce', array('value'=>$this->nonceKey), $anonFindElement, $this->postMode);
        
        // Check Nonce if in Post Mode
        if ($this->postMode) {
            $this->nonceValid = \TohirExternal\NonceUtil::check(FORM_SALT.'~'.$hiddenFormId->getValue(), $nonceElement->getValue());
        }
        
        // If nonce failed, mark form as invalid, and add an error message
        if (!$this->nonceValid ) {
            $this->isValid = FALSE;
            $this->validationErrors[] = 'Invalid form submission, please try again';
            
            // Generate a new Nonce Key and Element!
            $newNonceKey = \TohirExternal\NonceUtil::generate(FORM_SALT.'~'.$hiddenFormId->getValue(), FORM_TIMEOUT);
            $nonceElement = new FormElement_Hidden('__nonce', array('value'=>$newNonceKey), $anonFindElement);
        }
        
        // Add Rendered Items
        $this->renderedElements[] = $hiddenFormId->getRendered();
        $this->renderedElements[] = $nonceElement->getRendered();
        
        // Loop through elements
        foreach ($this->elements as $name => $element)
        {
            // Form Element Names MUST start with a letter, and may only contain letters, digits and underscores
            if (!preg_match('/\A(?:[a-z][\w|_]+)\Z/i', $name)) { // Regex Buddy: [a-z][\w|_]+
                throw new Exception('Invalid Element Name! Form Element Names MUST start with a letter, and may only contain letters, digits and underscores: <pre>'.htmlspecialchars($name).'</pre>');
            }
            
            $class = 'FormElement_'.strtoupper($element['type']);
            if (class_exists($class)) {
                
                // Nonce Valid should always be true for hidden elements to preserve their values
                $nonceValid = ($class == 'FormElement_HIDDEN') ? TRUE : $this->nonceValid;
                
                $item = new $class($name, $element, $anonFindElement, $this->postMode, $nonceValid, $this->formId, $this->elementLayout);
                
                $this->renderedElements[] = $item->getRendered();
                $this->validationRules = array_merge($this->validationRules, $item->getValidation());
                
                $itemValidationMessage = $item->getValidationMessage();
                if (!empty($itemValidationMessage)) {
                    $this->validationMessages[$name] = $itemValidationMessage;
                }
                
                // Adjust enctype if for File Upload
                if ($class == 'FormElement_FILE') {
                    $this->formParts['enctype'] = 'multipart/form-data';
                }
                
                $this->addOnJavascript = array_merge($this->addOnJavascript, $item->getAddOnJavascript());
                
                // If postMode, and nonce passes, then get the Posted values, and check Validation
                // If nonce fails, we dont retrieve posted values and do validation checks
                // This is to punish them!!!
                if ($this->postMode && $this->nonceValid && $class != 'FormElement_HTML') { // Ignore HTML type
                    $this->formData = array_merge($this->formData, $item->getPostData());
                    
                    // If Item is Not Valid
                    if (!$item->isValid()) {
                        
                        // Mark as such
                        $this->isValid = FALSE;
                        
                        // Get Validation Errors
                        $this->validationErrors = array_merge($this->validationErrors, $item->getValidationErrors());
                    }
                }
            } else {
                echo '<br /> - NO Class for Type '.$element['type'].' Yet<br />';
            }
        }
        
        // Add Submit Button
        $submitButton = new FormElement_SUBMIT('submitbutton', array('value'=>$this->formParts['submitbutton']));
        $this->renderedElements[] = $submitButton->getRendered();
    }
    
    /**
     * This is a closure function thats available to Form Elements to find other elements
     * Needed for validation rules: equalTo, differentTo
     */
    public function findElement($name)
    {
        if (array_key_exists($name, $this->elements)) {
            // Add Name to Element and Return
            return array_merge(array('name'=>$name), $this->elements[$name]);
        } else {
            return FALSE;
        }
    }
    
    /**
     * Method to render the form with HTML, JavaScript
     * @return string
     */
    public function render()
    {
        if ($this->postMode && count($this->validationErrors) > 0) {
            $str = '<ul class="formErrors"><li>';
            
            $str .= implode('</li><li>', $this->validationErrors);
            
            $str .= '</li></ul>';
        } else {
            $str = '';
        }
        
        return $str.$this->formStart().$this->formElements().$this->formEnd().$this->validationRules();
    }
    
    /**
     * Method to render the form with HTML, JavaScript
     * Though render() is preferred, this is kept for backward compatibility
     * @return string
     */
    public function display()
    {
        return $this->render();
    }
    
    
    /**
     * Method to get the form start tags
     * @return string
     */
    private function formStart()
    {
        $str = '<form id="'.$this->formId.'"';
        
        $possibleFormAttributes = array('name', 'method', 'action', 'class', 'onsubmit', 'enctype'); // What Else?
        
        foreach ($this->formParts as $element=>$value)
        {
            if (in_array($element, $possibleFormAttributes)) {
                $str .= ' '.$element.'="'.$value.'"';
            }
        }
        
        return $str.'>'.$this->formParts['form_before_elements_html'];
    }
    
    /**
     * Method to get the form end tags
     * @return string
     */
    private function formEnd()
    {
        return $this->formParts['form_after_elements_html'].'</form>';
    }
    
    /**
     * Method to get the renderedFormElements
     * @return string
     */
    private function formElements()
    {
        return join($this->renderedElements);
    }
    
    /**
     * Method to get the Validation Rules Javascript
     * @return string
     */
    private function validationRules()
    {
        // Ignore If No Rules?
        if (empty($this->validationRules)) {
            //return '';
        }
        
        $validation =
<<<ValidationRules

<script type="text/javascript">
    jQuery(document).ready(function() {
        jQuery("#{$this->formId}").validate({
            errorPlacement: function(errorLabel, element) {
                errorLabel.insertAfter(element);
            },
              
            rules:
/* Start of Validation Rules*/
[-VALIDATION-RULES-]
/* End of Validation Rules*/
            ,
 			messages: [-VALIDATION-MESSAGES-]
        });
        
        [-IMMEDIATE-VALIDATE-]
        
        [-ADDON-JAVASCRIPT-]
        
    });
</script>

ValidationRules;

         // JSON_PRETTY_PRINT only works in PHP 5.4
        if (version_compare(PHP_VERSION, '5.4.0') >= 0) {
            $validationRules = json_encode($this->validationRules, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES);
            $validationMessages = json_encode($this->validationMessages, JSON_PRETTY_PRINT);
        } else {
            $validationRules = json_encode($this->validationRules, JSON_UNESCAPED_SLASHES);
            $validationMessages = json_encode($this->validationMessages);
        }
        
        // Unescape LITERAL Code
        // Regexbuddy: "\[LITERAL\].*?\[LITERAL\]"
        $validationJS = preg_replace_callback(
            '/"\[LITERAL\].*?\[LITERAL\]"/',
            function ($matches) {
                
                $matches[0] = str_replace('"[LITERAL]', '', $matches[0]);
                $matches[0] = str_replace('[LITERAL]"', '', $matches[0]);
                $matches[0] = str_replace('\"', '"', $matches[0]);
                
                return $matches[0];
            },
            $validationRules
        );
        
        $validation = str_replace('[-VALIDATION-RULES-]', $validationJS, $validation);
        $validation = str_replace('[-VALIDATION-MESSAGES-]', $validationMessages, $validation);
        
        $immediateValidation = ($this->postMode && $this->nonceValid) ? 'jQuery("#'.$this->formId.'").valid();' : '';
        
        $validation = str_replace('[-IMMEDIATE-VALIDATE-]', $immediateValidation, $validation);
        
        if (!empty($this->addOnJavascript)) {
            $validation = str_replace('[-ADDON-JAVASCRIPT-]', join($this->addOnJavascript), $validation);
        } else {
            $validation = str_replace('[-ADDON-JAVASCRIPT-]', '', $validation);
        }
        
        return $validation;
    }
    
    /**
     * Method to get the posted form data
     * @return array
     */
    public function getFormData()
    {
        return $this->formData;
    }
    
    /**
     * Method to check if the form passed validation
     * @return boolean
     */
    public function isValid()
    {
        if (!$this->postMode) {
            throw new Exception('You are requesting isValid(), but did not set $postMode to TRUE in: $form = new FormGenerator($structure,$layout,$postMode=TRUE)');
        }
        
        return $this->isValid;
    }
    
    /**
     * Method to add a Validation Error
     * This is needed when a form passes it's validation,
     * but fails in validation that happens outside of the form class
     * @param string $error Validation Error
     */
    public function addFormValidationError($error)
    {
        if ($this->postMode) {
            $this->validationErrors[] = $error;
        }
    }
}

/**
 * @abstract FormElement_BASE
 * Base FormElement Class that all others have to extend
 */
abstract class FormElement_BASE
{
    protected $formElement = '';
    protected $labelElement = '';
    protected $validation = array();
    protected $addOnJavascript = array();
    
    protected $formId;
    protected $elementParams;
    protected $layout;
    
    protected $isValid = TRUE;
    
    protected $validationErrors = array();
    
    private $findElementMethod;
    
    /**
     * List of Options available here:
     * http://jqueryvalidation.org/documentation/
     *
     * Most allow required. Text has alot more
     */
    protected $validRules = array('required', 'remote');
    
    
    /**
     * These are the default values that can be overridden
     */
    protected $requiredParamsBase = array(
        'value' => '',
        'id' => '',
        'options' => '',
        'selected' => '',
        'label' => '',
        'cssClass' => '', // CSS Class
        'disabled' => '',
        'readonly' => '',
        'style' => '',
        'placeholder' => '',
        
        'layout' => '',
        
        'isHTMLLabel' => FALSE, // Some Elements support HTML within the label, otherwise label is escaped
        'labelCssClass' => '', // NOT YET IMPLEMENTED - UNDER CONSIDERATION
        
        'validation' => array(), // List of Validation Rules applied to element
        'extra' => '', // Extra HTML
        
        'html' => '', // For HTML Fragments
        'options' => array(), // For Dropdowns, multiselect
        'listsize' => 5, // For Select Lists
        'divider' => '<br />', // For Radio Buttons and Checkbox Groups
        'addempty' => TRUE, // For Dropdowns, multiselect
        'validationMessage' => '', // Override Validation Error Message
    );
    
    
    /**
     * Form Elements Constuctor
     * @param string $name Name of the Element
     * @param array $params Options/Attributes/Parameters of the Element
     * @param function $findElementMethod Anonymous Function
     * @param boolean $postMode Indicate whether we trying to retrieve submitted values
     * @param string $formId Form Id - used to generate unique element Ids
     * @param string $layout HTML Layout with Label and Element
     */
    public function __construct($name, $params, $findElementMethod='', $postMode=FALSE, $nonceValid=TRUE, $formId='', $layout='')
    {
        $requiredParams = array(
            'name' => $name,
            'postMode' => $postMode,
        );
        
        $requiredParams = array_merge($this->requiredParamsBase, $requiredParams);
        
        // Remove at this point, should no longer be available
        unset($this->requiredParamsBase);
        
        
        $this->findElementMethod = $findElementMethod;
        
        $this->elementParams = array_merge($requiredParams, $params);
        
        $dontNeedNameParamArray = array('FormElement_SUBMIT', 'FormElement_HTML');
        
        // Fatal Error - Name has to be set
        if (empty($this->elementParams['name']) && !in_array(get_class($this), $dontNeedNameParamArray)) {
            throw new Exception('Form has an element with no name '.get_class($this));
        }
        
        // Common Error - Validation should be an array
        if (!is_array($this->elementParams['validation'])) {
            throw new Exception('Validation for '.$this->elementParams['name'].' is not an array');
        }
        
        $this->formId = $formId;
        $this->elementId = $this->generateElementId($this->elementParams, $this->formId );
        
        // Layout can be overridden
        $this->layout = empty($this->elementParams['layout']) ? $layout: $this->elementParams['layout'];
        
        
        
        // Preserve and submitted values if in post mode
        if ($postMode  && $nonceValid) {
            $this->elementParams['value'] = $this->getPostValue(TRUE);
        }
        
        $this->generate();
        $this->generateValidation();
    }
    
    /**
     * Method to generate a CSS ID - Builds one if one does not exist.
     */
    private function generateElementId($elementParams, $formId)
    {
        return empty($elementParams['id']) ? $formId.'_'.$elementParams['name'] : $elementParams['id'];
    }
    
    abstract protected function generate();
    
    public function getRendered()
    {
        $layout = $this->layout;
        
        $layout = str_replace('[-LABEL-]', $this->labelElement, $layout);
        $layout = str_replace('[-ELEMENT-]', $this->formElement, $layout);
        $layout = str_replace('[-EXTRA-]', $this->elementParams['extra'], $layout);
        
        return $layout;
    }
    
    public function getValidation()
    {
        return $this->validation;
    }
    
    public function getValidationMessage()
    {
        return $this->elementParams['validationMessage'];
    }
    
    public function getAddOnJavascript()
    {
        return $this->addOnJavascript;
    }
    
    /**
     * Method to get the $_POST Value
     *
     * @param boolean $arrayToCommaSeparated Indicate whether Value should be modified so that it can be set
     * Mostly important for checkbox group. Array needs to become a comma separated string
     */
    protected function getPostValue($arrayToCommaSeparated=FALSE)
    {
        $value = isset($_POST[$this->elementParams['name']]) ? $_POST[$this->elementParams['name']] : '';
        
        if (is_array($value) && $arrayToCommaSeparated) {
            return implode(',', $value);
        } else {
            return $value;
        }
    }
    
    public function getPostData()
    {
        return array($this->elementParams['name'] => $this->getPostValue());
    }
    
    public function isValid()
    {
        return $this->isValid;
    }
    
    public function getValidationErrors()
    {
        return $this->validationErrors;
    }
    
    protected function keyValuesToString($array)
    {
        $str = '';
        foreach ($array as $key=>$value)
        {
            if (!empty($value) || $key == 'value') { // Only Allow Value to be blank
                $str .= ' '.$key.'="'.$value.'"';
            }
        }
        
        return $str;
    }
    
    protected function generateValidation()
    {
        // Only Bother if Not Empty, and is Array
        if (!empty($this->elementParams['validation']) && is_array($this->elementParams['validation'])) {
            
            foreach ($this->elementParams['validation'] as $validationRule=>$details)
            {
                // Check that Validation Rule is available for Element
                if (in_array($validationRule, $this->validRules)) {
                    
                    $this->generateValidationRuleJS($validationRule, $details);
                    
                    if ($this->elementParams['postMode']) {
                        $this->checkValidationRuleServerSide($validationRule, $details);
                    }
                    
                }
            }
        }
    }
    
    protected function generateValidationRuleJS($validationRule, $details)
    {
        $addValidatorMethod = 'addValidator_'.$validationRule;
        
        // Sometimes we haven't defined the rule below - lets not get ahead of ourselves
        try {
            $this->$addValidatorMethod($details);
        } catch (Exception $e) {
            throw new Exception($addValidatorMethod.' has not been defined!');
        }
        
    }
    
    protected function checkValidationRuleServerSide($validationRule, $details)
    {
        
        
        try {
            if (!FormValidator::$validationRule($details, $this->getPostValue())) {
                
                $this->isValid = FALSE;
                
                
                if (in_array($validationRule, array('equalTo', 'differentTo'))) {
                    $findElementMethod = $this->findElementMethod;
                    $extraInfo = $findElementMethod($details);
                } else {
                    $extraInfo = array();
                }
                
                
                $this->validationErrors[] = ValidationMessages::getMessage($validationRule, $this->elementParams, $this->elementId, $details, $extraInfo);
            }
        } catch (Exception $e) {
			//var_dump($e);
            throw new Exception('FormValidator::'.$validationRule.' has not been defined!');
        }
    }
    
    private function addValidationRule($rule,$value)
    {
        // Checks that Validation Array for Element has been setup
        if (!isset($this->validation[$this->elementParams['name']])) {
            $this->validation[$this->elementParams['name']] = array();
        }
        
        // Then Add
        $this->validation[$this->elementParams['name']][$rule] = $value;
    }
    
    private function addValidator_required($details)
    {
        if ($details == TRUE) {
            $this->addValidationRule('required', (bool)$details);
        }
    }
    
    private function addValidator_email($details)
    {
        if ($details == TRUE) {
            $this->addValidationRule('email', (bool)$details);
        }
    }
    
    private function addValidator_date($details)
    {
        if ($details == TRUE) {
            $this->addValidationRule('date', (bool)$details);
        }
    }
    
    private function addValidator_url($details)
    {
        if ($details == TRUE) {
            $this->addValidationRule('url', (bool)$details);
        }
    }
    
    private function addValidator_number($details)
    {
        if ($details == TRUE) {
            $this->addValidationRule('number', (bool)$details);
        }
    }
    
    private function addValidator_minlength($details)
    {
        $this->addValidationRule('minlength', (int)$details);
    }
    
    private function addValidator_maxlength($details)
    {
        $this->addValidationRule('maxlength', (int)$details);
    }
    
    private function addValidator_min($details)
    {
        $this->addValidationRule('min', (int)$details);
    }
    
    private function addValidator_max($details)
    {
        $this->addValidationRule('max', (int)$details);
    }
    
    private function addValidator_equalTo($details)
    {
        $findElementMethod = $this->findElementMethod;
        $element = $findElementMethod($details);
        
        if ($element != FALSE) {
            $this->addValidationRule('equalTo', '#'.$this->generateElementId($element, $this->formId));
        }
    }
    
    private function addValidator_differentTo($details)
    {
        $findElementMethod = $this->findElementMethod;
        $element = $findElementMethod($details);
        
        if ($element != FALSE) {
            $this->addValidationRule('differentTo', '#'.$this->generateElementId($element, $this->formId));
        }
    }
    
    private function addValidator_remote($details)
    {
        $this->addValidationRule('remote', $details);
    }
    
    private function addValidator_mimetype($details)
    {
        $this->addValidationRule('accept', $details); // For Mimetype
    }
    
    private function addValidator_extension($details)
    {
        $this->addValidationRule('extension', $details);
    }
    
    private function addValidator_maxfilesize($details)
    {
        // Server Side Validation
        //$this->addValidationRule('maxfilesize', $details);
    }
    
    private function addValidator_regex($details)
    {
        /*
        For Regex, $details needs to be an array, with jsRegex and phpRegex.
        This is for client side and serverside validation.
        
        Additional Rules around formatting:
        
        Example: /^ABCD_\d+$/
        
        1) Needs to be without first slash and last slash
            a) Remove first slash if exists
            b) Remove last slash if exists
        2) Add ^ to if not exists - start off string
        3) Add $ to if not exists - End off string
        
        
        Should be added as ^ABCD_\d+$
        
        
        No special changes required for the phpRegex - except to note it uses preg_match
        
        */
        
        // Only Add if jsRegex is specified. Useful if we dont want to expose the regex
        // Will only be determined server side
        if (isset($details['jsRegex']) && !empty($details['jsRegex'])) {
            $this->addValidationRule('regex', $details['jsRegex']);
        }
    }
    
    private function addValidator_captcha($details)
    {
        // Needs to be defined, but do nothing. Checked Server-Side
    }
    
}

class FormElement_LABEL
{
    public static function generate($for, $labelText, $escape=TRUE, $labelClass='')
	{
		if ($escape) {
			$labelText = htmlspecialchars($labelText);
		}
		
		if (!empty($labelClass)) {
			$labelClass = 'class="'.$labelClass.'"';
		}
		
		return '<label for="'.$for.'" '.$labelClass.'>'.$labelText.'</label>';
	}
}


class FormElement_SUBMIT extends FormElement_BASE
{
    // There are no Validation Rules for Submit Button
    protected $validRules = array();
    
    protected function generate()
    {
        $params = array(
            'type' => 'submit',
            'name' => $this->elementParams['name'],
            'value' => $this->elementParams['value'],
        );
        
        $this->formElement = '<input '.$this->keyValuesToString($params).' />';
    }
    
    /**
     * Only Hidden Input needs to override as we return something hidden
     */
    public function getRendered()
    {
        return $this->formElement;
    }
}

class FormElement_HTML extends FormElement_BASE
{
    // There are no Validation Rules for HTML Fragments
    protected $validRules = array();
    
    protected function generate()
    {
        $this->formElement = $this->elementParams['html'];
    }
    
    // Return without any label
    public function getRendered()
    {
        return $this->formElement;
    }
}

class FormElement_HIDDEN extends FormElement_BASE
{
    // There are no Validation Rules for Hidden Elements
    protected $validRules = array();
    
    protected function generate()
    {
        $params = array(
            'type' => 'hidden',
            'id' => $this->elementParams['id'],
            'name' => $this->elementParams['name'],
            'value' => htmlspecialchars($this->elementParams['value']),
        );
        
        $this->formElement = '<input '.$this->keyValuesToString($params).' />';
    }
    
    /**
     * Only Hidden Input needs to override as we return something hidden
     */
    public function getRendered()
    {
        return $this->formElement;
    }
    
    /**
     * Method to get Hidden Element Value
     * - This needs to be public to work with the formid and nonce elements.
     */
    public function getValue()
    {
        return $this->elementParams['value'];
    }
}

class FormElement_TEXTAREA extends FormElement_BASE
{
    // It is possible to validate that it's a date, email, url for textarea
    // Possible vs Should We?
    protected $validRules = array('required', 'email', 'date', 'url', 'minlength', 'maxlength', 'number');
    
    protected $cssClass = ''; // Needed so that it can be overridden by the WYSIWYG
    
    protected function generate()
    {
        // Consider Rows / Columns
        $params = array(
            'id' => $this->elementId,
            'name' => $this->elementParams['name'],
            'class' => $this->cssClass.$this->elementParams['cssClass'],
            'disabled' => $this->elementParams['disabled'],
            'style' => $this->elementParams['style'],
            'placeholder' => $this->elementParams['placeholder'],
            'readonly' => $this->elementParams['readonly'],
        );
        
        $this->formElement = '<textarea '.$this->keyValuesToString($params).'>'.htmlspecialchars($this->elementParams['value']).'</textarea>';
        
        if (!empty($this->elementParams['label'])) {
        	$isHTML = isset($this->elementParams['isHTMLLabel']) ? $this->elementParams['isHTMLLabel'] : FALSE;
		$label = $isHTML ? $this->elementParams['label'] : htmlspecialchars($this->elementParams['label']);
        	$this->labelElement = '<label for="'.$this->elementId.'">'.$label.'</label>';
        }
    }
}

class FormElement_HTMLEDITOR extends FormElement_TEXTAREA
{
    // Ignore, we can fix later
    protected $validRules = array('');
    
    protected $cssClass = 'ckeditor '; // To show it's a ckeditor
}

class FormElement_PASSWORD extends FormElement_BASE
{
    protected $validRules = array('required', 'remote', 'minlength', 'equalTo', 'regex');
    
    protected function generate()
    {
        $params = array(
            'type' => 'password',
            'id' => $this->elementId,
            'name' => $this->elementParams['name'],
            'value' => htmlspecialchars($this->elementParams['value']),
            'class' => $this->elementParams['cssClass'],
            'disabled' => $this->elementParams['disabled'],
            'style' => $this->elementParams['style'],
        );
        
        $this->formElement = '<input '.$this->keyValuesToString($params).' />';
        
        if (!empty($this->elementParams['label'])) {
            if ($this->elementParams['isHTMLLabel']) {
                $this->labelElement = '<label for="'.$this->elementId.'">'.$this->elementParams['label'].'</label>';
            } else {
                $this->labelElement = '<label for="'.$this->elementId.'">'.htmlspecialchars($this->elementParams['label']).'</labe
l>';
            }
        }
    }
}

class FormElement_TEXT extends FormElement_BASE
{
    protected $validRules = array('required', 'remote', 'email', 'date', 'url', 'minlength', 'maxlength', 'number', 'equalTo', 'differentTo', 'regex');
    
    protected function generate()
    {
        $params = array(
            'type' => 'text',
            'id' => $this->elementId,
            'name' => $this->elementParams['name'],
            'value' => htmlspecialchars($this->elementParams['value']),
            'class' => $this->elementParams['cssClass'],
            'disabled' => $this->elementParams['disabled'],
            'readonly' => $this->elementParams['readonly'],
            'style' => $this->elementParams['style'],
            'placeholder' => $this->elementParams['placeholder'],
        );
        
        $isHTML = isset($this->elementParams['isHTMLLabel']) ? $this->elementParams['isHTMLLabel'] : FALSE;
        
        $this->formElement = '<input '.$this->keyValuesToString($params).' />';
        
        $label = $isHTML ? $this->elementParams['label'] : htmlspecialchars($this->elementParams['label']);
        
        if (!empty($this->elementParams['label'])) {
            $this->labelElement = '<label for="'.$this->elementId.'">'.$label.'</label>';
        }
    }
}

class FormElement_EMAIL extends FormElement_TEXT
{
    protected function generate()
    {
        parent::generate();
        $this->elementParams['validation']['email'] = TRUE;
    }
}

class FormElement_DATE extends FormElement_TEXT
{
    protected function generate()
    {
        parent::generate();
        $this->elementParams['validation']['date'] = TRUE;
    }
}

class FormElement_URL extends FormElement_TEXT
{
    protected function generate()
    {
        parent::generate();
        $this->elementParams['validation']['url'] = TRUE;
    }
}

class FormElement_NUMBER extends FormElement_TEXT
{
    protected $validRules = array('required', 'minlength', 'maxlength', 'number', 'min', 'max');
    protected function generate()
    {
        parent::generate();
        $this->elementParams['validation']['number'] = TRUE;
    }
}

class FormElement_IMAGESELECTOR extends FormElement_TEXT
{
    protected $validRules = array('required');
    protected function generate()
    {
        $src = isset($this->elementParams['value']) ? 'src="'.$this->elementParams['value'].'"' : '';
        
        $img = '<img class="imageselector" id="img_'.$this->elementId.'" '.$src.' />';
        
        $this->elementParams['extra'] = $img.$this->elementParams['divider'].'<input type="button" value="Browse Server for Image" onclick="selectSingleFile(\'image\', \''.$this->elementId.'\');" />';
        
        $this->elementParams['cssClass'] .= ' imageselector';
        
        parent::generate();
    }
}

class FormElement_FILE extends FormElement_BASE
{
    // mimetype, multi separated by comma
    protected $validRules = array('required', 'mimetype', 'extension', 'maxfilesize'); //'remote', 
    
    private $requiredValidationPassed = TRUE;
    
    protected function generate()
    {
        $params = array(
            'type' => 'file',
            'id' => $this->elementId,
            'name' => $this->elementParams['name'],
            'class' => $this->elementParams['cssClass'],
            'disabled' => $this->elementParams['disabled'],
            'style' => $this->elementParams['style'],
        );
        
        $this->formElement = '<input '.$this->keyValuesToString($params).' />';
        
        if (!empty($this->elementParams['label'])) {
            $this->labelElement = '<label for="'.$this->elementId.'">'.htmlspecialchars($this->elementParams['label']).'</label>';
        }
    }
    
    /**
     * Values of File Uploads are never preserved for reuse.
     * Override and return blank
     */
    protected function getPostValue($arrayToCommaSeparated=FALSE)
    {
        return '';
    }
    
    /**
     * File Uploads Post Value is different to others
     * @return array Details of the File Upload
     */ 
    public function getPostData()
    {
        // Prepare Temp Storage Location
        $tempStoragePath = TMP_UPLOAD_LOCATION.uniqid().'/';
        
        if (!is_dir($tempStoragePath)) {
            mkdir($tempStoragePath, 0777, TRUE);
        }
        
        // Lets do the validation first!
        if (!empty($this->elementParams['validation']) && is_array($this->elementParams['validation'])) {
            $this->doFileUploadValidation($this->elementParams['validation']);
        }
        
        // If Required Validation Failed, We only submit that
        if (!$this->requiredValidationPassed) {
            return array($this->elementParams['name'] => '');
        }
        
        // If If Required Validation Passed, but there are other errors
        if (!$this->isValid) {
            return array($this->elementParams['name'] => '');
        }
        
        // Try to upload file
        try {
            
            // Make more secure
            $destination = $tempStoragePath.$_FILES[$this->elementParams['name']]['name'];
            
            move_uploaded_file($_FILES[$this->elementParams['name']]['tmp_name'], $destination);
            
            $data = array(
                'filename' => $_FILES[$this->elementParams['name']]['name'],
                'filepath' => $destination,
                'mimetype' => $_FILES[$this->elementParams['name']]['type'],
                'filesize' => filesize($destination),
                'storagepath' => $tempStoragePath,
            );
            
            
        } catch (\Exception $e) {
            
            // Fail!
            $this->isValid = FALSE;
            $this->validationErrors[] = 'Upload Error';
            return array($this->elementParams['name'] => '');
        }
        
        
        return array($this->elementParams['name'] => $data);
    }
    
    /**
     * File Uploads have their own way of validation - Ignore here, done in getPostData()
     * @param string $validationRule Validation Rule to be checked on File Uploads
     * @param mixed $details Validation Rule Parameters/Options
     * @return void
     */
    protected function checkValidationRuleServerSide($validationRule, $details)
    {
    }
    
    private function doFileUploadValidation($validationRules)
    {
        foreach ($validationRules as $validationRule=>$details)
        {
            $method = 'fileValidate_'.$validationRule;
            
            if (method_exists($this, $method)) {
                $this->$method($details);
            }
            
        }
    }
    
    private function fileValidate_required($details)
    {
        if ($details && empty($_FILES[$this->elementParams['name']]['name'])) {
            $this->requiredValidationPassed = FALSE;
            
            $this->isValid = FALSE;
            $this->validationErrors[] = ValidationMessages::getMessage('required', $this->elementParams, $this->elementId, $details);
        }
    }
    
    private function fileValidate_mimetype($details)
    {
        if (!empty($details) && !empty($_FILES[$this->elementParams['name']]['type'])) {
            
            // Convert to Array
            $mimetypes = explode(',', $details);
            
            if (!in_array($_FILES[$this->elementParams['name']]['type'], $mimetypes)) {
                $this->isValid = FALSE;
                $this->validationErrors[] = ValidationMessages::getMessage('mimetype', $this->elementParams, $this->elementId, $details);
            }
        }
        
        
    }
    
    private function fileValidate_maxfilesize($details)
    {
        if (!empty($details) && !empty($_FILES[$this->elementParams['name']]['size'])) {
            
            // Convert to Bytes
            $maxFileSize = FormClassUtils::humanReadableToBytes($details);
            
            if ($_FILES[$this->elementParams['name']]['size'] > $maxFileSize) {
                $this->isValid = FALSE;
                $this->validationErrors[] = ValidationMessages::getMessage('maxfilesize', $this->elementParams, $this->elementId, $details);
            }
        }
    }
    
    private function fileValidate_extension($details)
    {
        if (!empty($details) && !empty($_FILES[$this->elementParams['name']]['name'])) {
            
            // Convert to Array
            $extensions = explode(',', $details);
            
            $file_parts = pathinfo($_FILES[$this->elementParams['name']]['name']);
            
            if (!in_array($file_parts['extension'], $extensions)) {
                $this->isValid = FALSE;
                $this->validationErrors[] = ValidationMessages::getMessage('extension', $this->elementParams, $this->elementId, $details);
            }
        }
    }
    
}

class FormElement_DROPDOWN extends FormElement_BASE
{
    protected $validRules = array('required');
    
    protected function generate()
    {
        $params = array(
            'id' => $this->elementId,
            'name' => $this->elementParams['name'],
            'class' => $this->elementParams['cssClass'],
            'disabled' => $this->elementParams['disabled'],
            'style' => $this->elementParams['style'],
        );
        
        // Prepend with Empty Array
        if ($this->elementParams['addempty']) {
            array_unshift($this->elementParams['options'],array('value'=>'', 'label'=>''));
        }
        
        // Array with single item - Cant use empty as Zero could be a valid value
        $valueList = strlen(trim($this->elementParams['value'])) == 0 ? array() : array($this->elementParams['value']);
        
        $this->generateCode($params, $valueList); 
    }
    
    /**
     * Method to generate the select dropdown
     * @param array $params - Some items, needs different params to go in the select tag
     * @param array $valueList - We use in_array to check the value for cases where the are multiple values
     * If it's a single value, just convert it to an array with a single value, else split at the comma
     */
    protected function generateCode($params, $valueList)
    {
        $this->formElement = '<select '.$this->keyValuesToString($params);
 			
        if (!empty($this->elementParams['style'])) {
            $this->formElement .= ' style="'.$this->elementParams['style'].'" ';
        }
        
        $this->formElement .= '>'."\r\n";
        
        foreach ($this->elementParams['options'] as $option)
        {
            $value = isset($option['value']) ? 'value="'.htmlspecialchars($option['value']).'"' : '';
            $label = isset($option['label']) ? $option['label'] : '';
            $disabled = (isset($option['disabled']) && $option['disabled']) ? 'disabled="disabled"' : '';
            $selected = in_array($option['value'], $valueList) ? 'selected="selected"' : '';
            $cssClass = isset($option['class']) ? 'class="'.$option['class'].'"' : '';
            $title = isset($option['title']) ? 'title="'.htmlspecialchars($option['title']).'"' : '';
            
            $this->formElement .= "<option {$value} {$selected} {$disabled} {$cssClass} {$title}>".htmlspecialchars($label)."</option>\r\n";
        }
        
        $this->formElement .= '</select>';
        
        if (!empty($this->elementParams['label'])) {
            if ($this->elementParams['isHTMLLabel']) {
                $this->labelElement = '<label for="'.$this->elementId.'">'.$this->elementParams['label'].'</label>';
            } else {
                $this->labelElement = '<label for="'.$this->elementId.'">'.htmlspecialchars($this->elementParams['label']).'</labe
l>';
            }
        }
    }
}

class FormElement_MULTISELECT extends FormElement_DROPDOWN
{
    protected function generate()
    {
        $params = array(
            'id' => $this->elementId,
            'name' => $this->elementParams['name'].'[]',
            'multiple' => 'multiple',
            'size' => $this->elementParams['listsize'],
            'class' => $this->elementParams['cssClass'],
            'disabled' => $this->elementParams['disabled'],
            'style' => $this->elementParams['style'],
        );
        
        $this->generateCode($params, explode(',', $this->elementParams['value'])); // Array split by comma
    }
}

class FormElement_LIST extends FormElement_DROPDOWN
{
    protected function generate()
    {
        $params = array(
            'id' => $this->elementId,
            'name' => $this->elementParams['name'],
            'size' => $this->elementParams['listsize'],
            'class' => $this->elementParams['cssClass'],
            'disabled' => $this->elementParams['disabled'],
            'style' => $this->elementParams['style'],
        );
        
        $this->generateCode($params, array($this->elementParams['value'])); // Array with single item
    }
}

class FormElement_RADIOBUTTONGROUP extends FormElement_BASE
{
    protected $validRules = array('required');
    protected $inputType = 'radio';
    protected $elementNameSuffix = ''; // Checkbox Groups which extend this, needs this
    
    protected function generate()
    {
        $this->formElement = '';
        $divider = '';
        
        foreach ($this->elementParams['options'] as $option)
        {
            // Check that Value / Label is specified
            $value = isset($option['value']) ? $option['value'] : '';
            $label = isset($option['label']) ? $option['label'] : '';
            $labelClass = isset($option['labelClass']) ? $option['labelClass'] : '';
            $isHTML = isset($option['isHTMLLabel']) ? $option['isHTMLLabel'] : FALSE;
            $disabled = (isset($option['disabled']) && $option['disabled']) ? 'disabled="disabled"' : '';
            
            // Generate a UniqueId based on Value
            $id = md5($this->elementId.'_'.$value);
            
            $checked = $this->isChecked($value) ? 'checked="checked"' : '';
            
            $params = array(
                'id' => $id,
                'name' => $this->elementParams['name'].$this->elementNameSuffix,
                'type' => $this->inputType,
                'value' => htmlspecialchars($value),
                'class' => $this->elementParams['cssClass'],
                'style' => $this->elementParams['style'],
            );
            
            $this->formElement .= $divider."<label for=\"{$id}\" class=\"nowrap {$labelClass}\">";
            $this->formElement .= "<input {$this->keyValuesToString($params)} {$checked} {$disabled}/> ";
            $this->formElement .= $isHTML ? $label : htmlspecialchars($label);
            $this->formElement .= "</label>";
            
            $divider = $this->elementParams['divider'];
        }
        
        if (!empty($this->elementParams['label'])) {
            $this->labelElement = '<label for="'.$this->elementId.'">'.htmlspecialchars($this->elementParams['label']).'</label>';
        }
    }
    
    protected function isChecked($value)
    {
        return $value == $this->elementParams['value'];
    }
}

class FormElement_CHECKBOXGROUP extends FormElement_RADIOBUTTONGROUP
{
    protected $inputType = 'checkbox';
    protected $elementNameSuffix = '[]'; // So that it gets treated as an array
    
    protected function isChecked($value)
    {
        return in_array($value, explode(',', $this->elementParams['value']));
    }
}

class FormElement_MAPINPUT extends FormElement_BASE
{
    // There are no Validation Rules at present, will fix later
    protected $validRules = array();
    
    private $mapObjectKeys = array('latitude'=>'Latitude', 'longitude'=>'Longitude', 'zoomlevel'=>'Zoom Level');
    
    public function __construct($name, $params, $findElementMethod='', $postMode=FALSE, $nOnceValid=TRUE, $formId='', $layout='')
    {
        $this->requiredParamsBase['latitudeValue'] = '';
        $this->requiredParamsBase['longitudeValue'] = '';
        $this->requiredParamsBase['zoomlevelValue'] = '9';
        
        $this->requiredParamsBase['latitudeFieldName'] = 'map_latitude';
        $this->requiredParamsBase['longitudeFieldName'] = 'map_longitude';
        $this->requiredParamsBase['zoomlevelFieldName'] = 'map_zoomlevel';
        
        parent::__construct($name, $params, $findElementMethod, $postMode, $nOnceValid, $formId, $layout);
    }
    
    public function getPostData()
    {
        $values = array();
        
        foreach ($this->mapObjectKeys as $name=>$label)
        {
            $postKeyName = md5('map~'.$this->elementParams['name'].'~'.$name);
            
            $values[$this->elementParams[$name.'FieldName']] = isset($_POST[$postKeyName]) ? $_POST[$postKeyName] : '';
        }
        
        return $values;
    }
    
    protected function generate()
    {
        $id = md5('map~'.$this->elementParams['name']);
        
        $this->formElement = '<div>';
        
        foreach ($this->mapObjectKeys as $name=>$label)
        {
            $divStyle = 'width:30%; float:left;';
            
            $latitudeParams = array(
                'type' => 'text',
                'placeholder' => $label,
                'value' => $this->elementParams[$name.'Value'],
                'name' => md5('map~'.$this->elementParams['name'].'~'.$name),
                'id' => md5('map~'.$this->elementParams['name'].'~'.$name),
                'class' => 'mapinput_'.$name,
                'maptarget' => $id,
            );
            $this->formElement .= '<div style="'.$divStyle.'"><input '.$this->keyValuesToString($latitudeParams).' /></div>';
        }
        
        $this->formElement .= '<br clear="both" /></div>';
        
        $divParams = array(
            'id' => $id,
            'class' => $this->elementParams['cssClass'],
            'style' => $this->elementParams['style'],
        );
        
        $this->formElement .= '<div '.$this->keyValuesToString($divParams).'></div>';
        
        
        if (!empty($this->elementParams['label'])) {
            $this->labelElement = '<label>'.htmlspecialchars($this->elementParams['label']).'</label>';
        }
        
        
        
        $jsObjectValue = array(
            'targetMapId' => $id,
            'zoomlevel' => (int)$this->elementParams['zoomlevelValue'], // We always set this
            
            'latUpdate' => md5('map~'.$this->elementParams['name'].'~latitude'),
            'lngUpdate' => md5('map~'.$this->elementParams['name'].'~longitude'),
            'zoomUpdate' => md5('map~'.$this->elementParams['name'].'~zoomlevel'),
        );
        
        // And $this->elementParams['zoomlevelValue'] ??
        if (FormClassUtils::emptyVars($this->elementParams['latitudeValue'], $this->elementParams['longitudeValue'])) {
            $jsObjectValue['hasMarker'] = FALSE;
        } else {
            $jsObjectValue['hasMarker'] = TRUE;
            $jsObjectValue['latitude'] = (float)$this->elementParams['latitudeValue'];
            $jsObjectValue['longitude'] = (float)$this->elementParams['longitudeValue'];
        }
        
        
        if (!empty($jsObjectValue)) {
            $json = json_encode($jsObjectValue);
            $this->addOnJavascript[] = "loadMapInput({$json});";
        }
    }
}

class FormClassUtils
{
    
    /**
     * Convert human readable file size (e.g. "10K" or "3M") into bytes
     *
     * @param  string $input
     * @return int
     */
    public static function humanReadableToBytes($input)
    {
        $number = (int)$input;
        $units = array(
            'b' => 1,
            'k' => 1024,
            'm' => 1048576,
            'g' => 1073741824
        );
        $unit = strtolower(substr($input, -1));
        if (isset($units[$unit])) {
            $number = $number * $units[$unit];
        }
        
        return $number;
    }
    
    /**
     * Pass multiple values to check if any of them are empty
     */
    public static function emptyVars()
    {
        foreach(func_get_args() as $arg)
        {
            if(empty($arg)) {
                return TRUE;
            } else {
                continue;
            }
        }
        return FALSE;
    }
}



use Respect\Validation\Validator as RespectValidator;

class FormValidator
{
    
    public static function required($details, $value)
    {
        // required==FALSE
        if ($details === FALSE) {
            return TRUE;
        }
        
        if (is_array($value)) {
            return count($value) > 0;
        } else if ($value === '0') { // Zero as a string is valid
            return TRUE;
        } else {
            return RespectValidator::notEmpty()->validate((string)$value);
        }
    }
    
    public static function email($details, $value)
    {
        if (empty($value) || !$details) {
            return TRUE;
        }
        
        return RespectValidator::email()->validate($value);
    }
    
    public static function date($details, $value)
    {
        if (empty($value) || !$details) {
            return TRUE;
        }
        
        return RespectValidator::date()->validate($value);
    }
    
    public static function url($details, $value)
    {
        if (empty($value) || !$details) {
            return TRUE;
        }
        
        return RespectValidator::url()->validate($value);
    }
    
    public static function number($details, $value)
    {
        if (strlen($value) == 0) {
            return TRUE;
        } else if ($value === '0') { // Zero as a string is valid
            return TRUE;
        }
        return RespectValidator::intVal()->validate($value);
    }
    
    public static function minlength($details, $value)
    {
        return RespectValidator::stringType()->length($details, null)->validate($value);
    }
    
    public static function maxlength($details, $value)
    {
        return RespectValidator::stringType()->length(null, $details)->validate($value);
    }
    
    public static function min($details, $value)
    {
        return RespectValidator::oneOf(
            RespectValidator::int()->min((int)$details),
            RespectValidator::int()->equals((int)$details)
        )->validate($value);
    }
    
    public static function max($details, $value)
    {
        return RespectValidator::oneOf(
            RespectValidator::int()->max((int)$details),
            RespectValidator::int()->equals((int)$details)
        )->validate($value);
    }
    
    public static function equalTo($details, $value)
    {
        return isset($_POST[$details]) && $_POST[$details] == $value;
    }
    
    public static function differentTo($details, $value)
    {
        return isset($_POST[$details]) && $_POST[$details] != $value;
    }
    
    public static function remote($details, $value)
    {
        return TRUE; /* @todo Fix*/
    }
    
    public static function regex($details, $value)
    {
        if (isset($details['phpRegex'])) {
            return preg_match($details['phpRegex'], $value); /* @todo Fix*/
        } else {
            return TRUE;
        }
    }
    
}

/**
 * Class to generate Validation Error Messages
 */
class ValidationMessages
{
    /**
     * Method to get a validation error message
     * @param string $validator Validation Rule that Failed
     * @param array $element Element Properties
     * @param string $elementId CSS Id of the Form Element
     * @param string $validationDetails Validation Rule Properties (Like for minlength, etc)
     */
    public static function getMessage($validator, $element, $elementId, $validationDetails, $extra=array())
    {
        $label = empty($element['label']) ? $element['name'] : $element['label'];
        $label = htmlspecialchars($label);
        
        switch ($validator)
        {
            case 'required':
                $str = "{$label} is required and cannot be empty";
                break;
            case 'email':
                $str = "{$label} needs to be an email address";
                break;
            case 'date':
                $str = "{$label} needs to be a date";
                break;
            case 'url':
                $str = "{$label} needs to be a URL";
                break;
            case 'number':
                $str = "{$label} needs to be a number";
                break;
            case 'minlength':
                $str = "{$label} needs to have a minimum of {$validationDetails} characters";
                break;
            case 'maxlength':
                $str = "{$label} needs to have a maximum of {$validationDetails} characters";
                break;
            case 'min':
                $str = "{$label} needs to be greater than or equal to {$validationDetails}";
                break;
            case 'max':
                $str = "{$label} needs to be less than or equal to {$validationDetails}";
                break;
            case 'mimetype':
                $validationDetails = str_replace(',', ', ', $validationDetails);
                $str = "{$label} is not in the correct file format: {$validationDetails}";
                break;
            case 'maxfilesize':
                $str = "{$label} is larger than allowed file size: {$validationDetails}";
                break;
            case 'extension':
                $validationDetails = str_replace(',', ', ', $validationDetails);
                $str = "{$label} does not have required extension: {$validationDetails}";
                break;
            case 'equalTo':
                $otherLabel = empty($extra['label']) ? $extra['name'] : $extra['label'];
                $otherLabel = htmlspecialchars($otherLabel);
                
                $str = "{$label} needs to be the same as: {$otherLabel}";
                break;
            case 'differentTo':
                $otherLabel = empty($extra['label']) ? $extra['name'] : $extra['label'];
                $otherLabel = htmlspecialchars($otherLabel);
                
                $str = "{$label} needs to be different to: {$otherLabel}";
                break;
            
            
            // remote
            // regex
            
            default:
                $str = "Something is wrong with {$label} - {$validator}";
                break;
        }
        
        return self::generateMessage($elementId, $str);
    }
    
    public static function generateMessage($elementId, $str)
    {
        return '<label for="'.$elementId.'">'.$str.'</label>';
    }
}
