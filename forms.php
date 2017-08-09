<?php
/*
Plugin Name: Forms
Plugin URI: 
Description: Helpers to write forms once, and have them self-validate. The validating results are populated into the original HTML form and sent as an email. (Results can also be attached as a CSV file with Name/Value pairs)
Version: 0.1.9
Author: Weston Ruter
Author URI: http://weston.ruter.net/
Copyright: 2009, Weston Ruter, Shepherd Interactive. GPL 3 License.

I thought: why are we trying to define a nice clean datastructure for the schema, when this needs to be represented by a sloppy form. We need to start
with the sloppy form and derive the schema from it! Simply define a function in functions.php which returns a FORM element, then in any page, add a [form]
shortcode with a name parameter which equals the name of the function, or alternatively this may be specified in the postmeta "form_name" so that no
attribute is needed in the shortcode.

 - The validating results are populated into the original HTML form and sent as an email. (Results can also be attached as a CSV file with Name/Value pairs)
 - For invalid controls, wrap their labels with STRONG@class=error
 - JavaScript client library is automatically included in the page and this script does interactive validation on the client, the same which is done on the server
 - Include a register_form('function_name') so that we can eventually have a drop down?
 - Same actions must happen on the server as the client (validation)
 - What to do if no success URL or page ID is supplied? What if shotcode content?
 - Auto-detect sender name and email
 - Auto-detect the subject of the email (the .form_subject field?)
 - We need a better way of displaying inline error messages

*/

/**
 * Turn on output buffering if this is a response for a form submission (so we can set HTTP status)
 */
function si_form_init(){
	if(!empty($_POST) || !empty($_GET))
		ob_start();
	add_filter('form_markup', 'si_form_default_markup_filter');
}
add_action('template_redirect', 'si_form_init');


/**
 * Serialize the form
 */
function si_form_serialize(&$doc, &$xpath){
	return apply_filters('form_markup', $doc->saveXML($doc->documentElement));
}

/**
 * Default filter which ensures that empty TEXTAREA 
 */
function si_form_default_markup_filter($markup){
	return preg_replace('{<textarea(.*?)/>}', '<textarea $1></textarea>', $markup);
}

/**
 * Helper function that formats error messages for output.
 */
function si_form_error($message, $type = '',  $source = ''){
	$html = '<p style="color:red"><em>';
	if(!$type)
		$type = __("Error");
	$html .= "<strong>" . htmlspecialchars($type) . "</strong>: ";
	$html .= $message;
	$html .= '</em></p>';
	if($source){
		$html .= '<pre style="margin-left:5px; border-left:solid 1px red; padding-left:5px;"><code class="xhtml malformed">';
		$html .= htmlspecialchars($source);
		$html .= '</code></pre>';
	}
	return $html;
}

/**
 * When POSTing data, the shortcode is called twice, so the results from the first call should be cached so that they
 * don't have to be processed a second time. The array is indexed by form (function) name.
 * @global array $si_form_shortcode_cache  
 */
//$si_form_shortcode_cache = array();

/**
 * Registers a 'form' shortcode that has a required @name param indicating the function name
 * which returns the HTML code for the shortcode
 */
function si_form_shortcode_handler($atts, $content = null){
	global $si_form_shortcode_cache, $post;
	extract(shortcode_atts(array(
		'name' => '',
		'recipient' => '',
		'subject' => '',
		'success_url' => '',
		#'send_email'  => true,
		'success_page_id' => 0,
		'cc_sender' => false,
		'html_email' => true
	), $atts));
	
	//if(!is_null($content))
		//return si_form_error(sprintf(__("Enclosing form short codes not supported."), htmlspecialchars($name)));
	
	//Serve cached output if it has already been processed
	if(!empty($si_form_shortcode_cache[$name]))
		return $si_form_shortcode_cache[$name];

	//Error: missing shortcode 'name' attribute
	if(!$name && !($name = get_post_meta($post->ID, 'form_name', true)))
		return si_form_error(sprintf(__("Missing required 'form_name' postmeta or 'name' attibute for 'form' shortcode, eg: <code>[form name=\"my_form_function\"]</code>")));
	
	//Error: missing shortcode 'name' attribute
	if(!$recipient && !($recipient = get_post_meta($post->ID, 'form_recipient', true))){
		$recipient = get_option('admin_email');
		#return si_form_error(sprintf(__("Missing required 'form_recipient' postmeta or 'recipient' attibute for 'form' shortcode.")));
	}
	
	//Shortcode 'subject' attribute
	if(!$subject && !($subject = get_post_meta($post->ID, 'form_subject', true)))
		$subject = "Form submission: " . get_the_title();
	
	//Shortcode 'success_page_id' attribute 
	if(!$success_page_id)
		$success_page_id = (int)get_post_meta($post->ID, 'form_success_page_id', true);
	
	//success_page_id overwrites success_url
	if($success_page_id)
		$success_url = get_permalink($success_page_id);
	
	//Shortcode 'success_url' attribute
	if(!$success_url)
		$success_url = get_post_meta($post->ID, 'form_success_url', true);
	
	//Option: send_email
	#$_send_email = get_post_meta($post->ID, 'form_send_email', false);
	#if(count($_send_email))
	#	$send_email = (boolean)$_send_email[0];
	
	//Option: cc_sender
	#$_cc_sender = get_post_meta($post->ID, 'form_cc_sender', false);
	#if(count($_cc_sender))
	#	$cc_sender = (boolean)$_cc_sender[0];
	
	//Option: cc_sender
	$_html_email = get_post_meta($post->ID, 'form_html_email', false);
	if(count($_html_email))
		$html_email = (bool)intval($_html_email[0]);
	
	//Error: name does not correspond to an existing function
	if(!function_exists($name))
		return si_form_error(sprintf(__("No function <code>%s</code> exists which returns the HTML for this form."), htmlspecialchars($name)));

	//Call the function and grab the results (if nothing, output a comment noting that it was empty)
	$xhtml = call_user_func_array($name, array($atts, $content));
	if(!$xhtml)
		return "<!-- form handler '$name' returned nothing -->";

	//Parse the form, return error if isn't well-formed
	$doc = new DOMDocument();
	if(!$doc->loadXML('<?xml version="1.0" encoding="utf-8"?>' . $xhtml))
		return si_form_error(sprintf(__("The function <code>%s</code> did not return wellformed XML:"), htmlspecialchars($name)), __('XML Parse Error'), $xhtml);
	$xpath = new DOMXPath($doc);
	
	//Error: root element must be "form"
	if($doc->documentElement->nodeName != 'form')
		return si_form_error(sprintf(__("The function <code>%s</code> did not return valid XML. Root element must be <code>form</code>:"), htmlspecialchars($atts['name'])), __('XML Wellformedness Error'), $xhtml);
	$form = $doc->documentElement;
	
	//Set the default attributes on the FORM element
	if(!$form->hasAttribute('action'))
		$form->setAttribute('action', get_permalink());
	if(!$form->hasAttribute('method'))
		$form->setAttribute('method', 'post');
	if(!$form->hasAttribute('id'))
		$form->setAttribute('id', $name);
	
	//Populate the form with the values provided in the request
	$items = si_form_populate_with_request_and_return_values($doc, $xpath);
	
	$invalidCount = 0;
	$invalidElements = array();
	
	//Allow the form to be customized
	do_action('form_before_validation', $name, $doc, $xpath);
	
	//Detect whether or not any of the elements are in error (only do this if the request method is the same as the form's method,
	//  so that we can pre-fill form values for POST requests with GET parameters.)
	if(strtoupper($doc->documentElement->getAttribute('method')) == $_SERVER['REQUEST_METHOD'] &&
	   (($_SERVER['REQUEST_METHOD'] == 'POST' && !empty($_POST)) ||
		($_SERVER['REQUEST_METHOD'] == 'GET'  && !empty($_GET))))
	{
		
		foreach($xpath->query("//*[@name and not(@disabled) and not(@readonly)]") as $input){
			$invalidTypes = array();
			if($input->nodeName == 'textarea')
				$value = (string)$input->textContent;
			else if($input->nodeName == 'select'){
				$selectedOption = $xpath->query(".//option[@selected]", $input)->item(0); //TODO: no multiple values supported
				if($selectedOption){
					$value = $selectedOption->hasAttribute('value') ? $selectedOption->getAttribute('value') : $selectedOption->textContent;
				}
				else {
					$value = null;
				}
			}
			else
				$value = (string)$input->getAttribute('value');
			
			//REQUIRED
			//If the element is required, and its value DOM attribute applies and is in the mode value, and the element is
			//  mutable, and the element's value is the empty string, then the element is suffering from being missing.
			if($input->hasAttribute('required') && !$value){
				$invalidTypes[] = 'valueMissing';
			}
			else {
				//PATTERN
				//If the element's value is not the empty string, and the element's pattern  attribute is specified and the 
				//  attribute's value, when compiled as an ECMA 262 regular expression with the global, ignoreCase, and multiline
				//  flags disabled (see ECMA 262, sections 15.10.7.2 through 15.10.7.4), compiles successfully but the resulting
				//  regular expression does not match the entirety of the element's value, then the element is suffering from a pattern mismatch. [ECMA262]
				if($value && $input->getAttribute('pattern') && !preg_match('/^(?:' . $input->getAttribute('pattern') . ')$/', $value)){
					$invalidTypes[] = 'patternMismatch';
				}
				
				//MAXLENGTH
				if((int)$input->getAttribute('maxlength') && mb_strlen($value, 'utf-8') > (int)$input->getAttribute('maxlength')){
					$invalidTypes[] = 'tooLong';
				}
				
				//Input types
				switch($input->getAttribute('type')){
					case 'email':
						if(!preg_match('/.+@.+(\.\w+)$/', $value)){
							$invalidTypes[] = 'typeMismatch';
						}
						break;
				}
				
				//Custom Validity
				$validationMessage = apply_filters('form_control_custom_validity', '', $input, $name, $xpath);
				if($validationMessage){
					$input->setAttribute('data-validationMessage', $validationMessage);
					$invalidTypes[] = 'customError';
				}
			}
			
			//Set the state of the input if it is invalid
			if(!empty($invalidTypes)){
				$input->setAttribute('class', $input->getAttribute('class') . ' invalid');
				$invalidElements[] = $input;
				$input->setAttribute('data-invalidity', join(' ', $invalidTypes));
				//TODO: Wrap a strong element around the text content of the invalid INPUT's LABEL?				
				$invalidCount++;
			}
		}
	
		//If there is an invalid element, then the form is invalid; autofocus on it
		if(count($invalidElements)){
			@status_header(400);
			$form->setAttribute('class', $form->getAttribute('class') . ' form_error_400');
			
			//Remove any existing autofocus, and set it on the first invalid element
			foreach($xpath->query("//*[@autofocus]") as $input)
				$input->removeAttribute('autofocus');
			$invalidElements[0]->setAttribute('autofocus','autofocus');
			
			if(count($invalidElements) == 1)
				si_form_populate_errors($doc, $xpath, __('There was an error with your form submission.'));
			else
				si_form_populate_errors($doc, $xpath, __('There were errors with your form submission.'));
			
			return si_form_serialize($doc, $xpath); //apply_filters('form_markup', $doc->saveXML($doc->documentElement));
		}
		//Try processing the data
		else {
			try {
				do_action('form_process_submission', $name, $doc, $xpath);
			
				
				$formSerialized = si_form_serialize($doc, $xpath); //apply_filters('form_markup', $doc->saveXML($doc->documentElement));
				
				$docEmail = $doc->cloneNode(true);
				do_action('form_before_controls_removed', $name, $docEmail);
				
				if($html_email){
					$headers = array('Content-type: text/html; charset=utf-8');
					$emailContents = si_form_remove_controls($docEmail);
				}
				else {
					$headers = array('Content-type: text/plain; charset=utf-8');
					$emailContents = '';
					foreach($items as $itemName => $itemContents){
						foreach($itemContents as $part){
							if(!$part['input'] || $part['input']->getAttribute('type') != 'hidden'){
								$emailContents .= preg_replace('/ +/', ' ', ($part['label'] ? $part['label']->firstChild->textContent : '') . ' ' . $part['value']) . "\r\n";
							}
						}
					}
				}
				
				$fullnameInput =  $xpath->query('//input[@type = "text" and @value and @value != "" and contains(@name, "name")]')->item(0);
				$firstnameInput = $xpath->query('//input[@type = "text" and @value and @value != "" and contains(@name, "name") and (contains(@name, "first") )]')->item(0);
				$lastnameInput  = $xpath->query('//input[@type = "text" and @value and @value != "" and contains(@name, "name") and (contains(@name, "last") or contains(@name, "surname") )]')->item(0);
				
				//foreach($xpath->query('//input[@type = "text"]') as $input){
				//	if($input->getAttribute('value') && preg_match('/\bfn\b/', $input->getAttribute('class'))){
				//		$nameInput = $input;
				//		break;
				//	}
				//}
				
				$submitterName = "";
				if($firstnameInput && $lastnameInput)
					$submitterName = $firstnameInput->getAttribute('value') . ' ' . $lastnameInput->getAttribute('value');
				else if($fullnameInput)
					$submitterName = $fullnameInput->getAttribute('value');
				
				$emailInput = $xpath->query('//input[@type = "email"]')->item(0);
				if($emailInput && $emailInput->getAttribute('value')){
					if($submitterName){
						//TODO: How should these values be escaped?
						$from = '"' . str_replace('"', "''", stripslashes($submitterName)) . '" ';
						$from .= '<' . $emailInput->getAttribute('value') . '>';
					}
					else {
						$from = $emailInput->getAttribute('value');
					}
					$headers[] = "From: $from";
				}
				
				//Construct CC
				$customCC = apply_filters('form_submission_recipient_cc', '', $name, $doc, $xpath);
				if($cc_sender || $customCC){
					$cc = array();
					if($cc_sender && !empty($from))
						$cc[] = $from;
					if($customCC)
						$cc[] = $customCC;
					
					$headers[] = "CC: " . join(', ', $cc);
				}
				
				//Construct BCC
				$customBCC = apply_filters('form_submission_recipient_bcc', '', $name, $doc, $xpath);
				if($customBCC)
					$headers[] = "BCC: $customBCC";
				$recipient = apply_filters('form_submission_recipient', $recipient, $name, $doc, $xpath);
				
				//Filter the subject
				$subject = apply_filters('form_submission_subject', $subject, $name, $doc, $xpath);
				
				//Send mail
				$success = null;
				if($recipient){
					$success = @wp_mail($recipient, $subject, $emailContents, join("\r\n", $headers));
					do_action('form_email', $name, $success, $recipient, $subject, $emailContents, join("\r\n", $headers), $doc, $xpath);
				}
				
				if(!$recipient || $success){
					$formSerialized = $emailContents;
					$success_url = apply_filters('form_success_url', $success_url, $name, $doc, $xpath);
					do_action('form_success', $submitterName, $success_url);
					if($success_url)
						wp_redirect($success_url, 303);
				}
				else {
					throw new Exception(__('We were unable to accept your request at this time (unable to send email). Please try again.'));
				}
				return $formSerialized;
			}
			catch(Exception $e){
				@status_header(500);
				si_form_populate_errors($doc, $xpath, $e->getMessage());
				
				//Set the autofocus on the submit button (this only works if there is only one submit button! TODO)
				$submit = $xpath->query('//*[@type = "submit"]')->item(0);
				if($submit){
					foreach($xpath->query("//*[@autofocus]") as $input)
						$input->removeAttribute('autofocus');
					$submit->setAttribute('autofocus','autofocus');
				}
				
				return si_form_serialize($doc, $xpath); //apply_filters('form_markup', $doc->saveXML($doc->documentElement));
			}
		}
	}
	else {
		return si_form_serialize($doc, $xpath); //apply_filters('form_markup', $doc->saveXML($doc->documentElement));
	}

	#return $si_form_shortcode_cache[$name];
}
add_shortcode('form', 'si_form_shortcode_handler');


/**
 * Populate the form with the error message
 */
function si_form_populate_errors(&$doc, &$xpath, $message){

	//Populate the error message
	$containers = $xpath->query('//*[contains(@class, "form_error_message")]');
	if($containers->length){
		foreach($containers as $container){
			while($container->firstChild)
				$container->removeChild($container->firstChild);
			$em = $doc->createElement('em');
			$em->appendChild($doc->createTextNode($message));
			$container->appendChild($em);
			
			//Make sure that it's visible
			$container->removeAttribute('hidden');
			if($container->hasAttribute('style'))
				$container->setAttribute('style', preg_replace('/display:\s*none\s*;?|visibility:\s*hidden\s*;?/i', '', $container->getAttribute('style')));
		}
	}
	else {
		$notice = $doc->createElement('p');
		$notice->setAttribute('class', 'form_error_message');
		$em = $doc->createElement('em');
		$em->appendChild($doc->createTextNode($message));
		$notice->appendChild($em);
		$form->insertBefore($notice, $form->firstChild);
	}
}




/**
 * Populate the form with the values present in the request
 */
function si_form_populate_with_request_and_return_values(&$doc, &$xpath){

	if($_SERVER['REQUEST_METHOD'] == 'POST')
		$request = &$_POST;
	else if($_SERVER['REQUEST_METHOD'] == 'GET')
		$request = &$_GET;
	else
		return null;
	
	$items = array();
		
	$populatedValues = 0;
	
	foreach($request as $attrName => $attrValue){
		if(!isset($items[$attrName]))
			$items[$attrName] = array();
		
		/*** Array values *********************************************/
		if(is_array($attrValue)){
			#$items[$inputName]['values'] = $attrValue;
			
			//Strip slashes if magic quotes are turned on
			if(get_magic_quotes_gpc()){
				foreach($attrValue as &$v){
					$v = stripslashes($v);
				}
			}
			
			//Iterate over all form elements with the current name
			$inputs = $xpath->query("//*[@name='{$attrName}[]']");
			if(!$inputs->length)
				continue;
			$populatedValues++;
				
			foreach($inputs as $input){
				$isRadioOrCheckbox = ($input->getAttribute('type') == 'radio' || $input->getAttribute('type') == 'checkbox');
				if(!$isRadioOrCheckbox || $input->hasAttribute('checked')){
					$item = array(
						'value' => $attrValue,
						'label' => '',
						'input' => $input
					);
					if(!$isRadioOrCheckbox){
						$item['label'] = $xpath->query("//label[@for = '" . $input->getAttribute('id') . "']")->item(0);
					}
					$items[$attrName][] = $item;
				}
				
				switch($input->nodeName){
					//INPUT elements
					case 'input':
						switch($input->getAttribute('type')){
							case 'checkbox':
							case 'radio':
								if($input->getAttribute('value') == @$attrValue[0] || (!$input->hasAttribute('value') && @$attrValue[0] == 'on')){
									$input->setAttribute('checked','checked');
									@array_shift($attrValue);
								}
								else {
									$input->removeAttribute('checked');
								}
								break;
							default:
								$input->setAttribute('value', @$attrValue[0]);
								@array_shift($attrValue);
								break;
						}
						break;
					//TEXTAREA element
					case 'textarea':
						while($input->firstChild)
							$input->removeChild($input->firstChild);
						if(@$attrValue[0]){
							$input->appendChild($doc->createTextNode(@$attrValue[0]));
							@array_shift($attrValue);
						}
						break;
					//SELECT element
					case 'select':
						$options = $input->getElementsByTagName('option');
						if($options->length){
							foreach($options as $option){
								//If the OPTION has a @value
								if($option->hasAttribute('value')){
									if($option->getAttribute('value') == @$attrValue[0]){
										$option->setAttribute('selected','selected');
										@array_shift($attrValue);
									}
									else {
										$option->removeAttribute('selected');
									}
								}
								//If value passed from child node
								else {
									if((!@$attrValue[0] && !$option->firstChild) || ($option->firstChild && $option->firstChild->nodeValue == @$attrValue[0])){
										$option->setAttribute('selected','selected');
										@array_shift($attrValue);
									}
									else
										$option->removeAttribute('selected');
								}
							}
						}
						break;
				}
			}
		}
		/*** Scalar values *********************************************/
		else {
			if(get_magic_quotes_gpc())
				$attrValue = stripslashes($attrValue);
			
			$inputs = $xpath->query("//*[@name='$attrName']");
			if($inputs->length){ //== 1
				$populatedValues++;
			
				foreach($inputs as $input){
					$isRadioOrCheckbox = ($input->getAttribute('type') == 'radio' || $input->getAttribute('type') == 'checkbox');
					if(!$isRadioOrCheckbox || $input->hasAttribute('checked')){
						$item = array(
							'value' => $attrValue,
							'label' => '',
							'input' => $input
						);
						if(!$isRadioOrCheckbox){
							$item['label'] = $xpath->query("//label[@for = '" . $input->getAttribute('id') . "']")->item(0);
						}
						$items[$attrName][] = $item;
					}
					//$items[$attrName][] = array(
					//	'label' => $xpath->query("//label[@for = '" . $input->getAttribute('id') . "']")->item(0),
					//	'value' => $attrValue
					//);
					
					switch($input->nodeName){
						//INPUT elements
						case 'input':
							switch($input->getAttribute('type')){
								case 'checkbox':
								case 'radio':
									if($input->getAttribute('value') == @$attrValue || (!$input->hasAttribute('value') && @$attrValue == 'on')) //if($input->getAttribute('value') == $attrValue)
										$input->setAttribute('checked','checked');
									else
										$input->removeAttribute('checked');
									break;
								default:
									$input->setAttribute('value', $attrValue);
									break;
							}
							break;
						//TEXTAREA element
						case 'textarea':
							while($input->firstChild)
								$input->removeChild($input->firstChild);
							$input->appendChild($doc->createTextNode($attrValue));
							break;
						//SELECT element
						case 'select':
							$options = $input->getElementsByTagName('option');
							if($options->length){
								foreach($options as $option){
									//If the OPTION has a @value
									if($option->hasAttribute('value')){
										if($option->getAttribute('value') == $attrValue)
											$option->setAttribute('selected','selected');
										else
											$option->removeAttribute('selected');
									}
									//If value passed from child node
									else {
										if((!$attrValue && !$option->firstChild) || ($option->firstChild && $option->firstChild->nodeValue == $attrValue))
											$option->setAttribute('selected','selected');
										else
											$option->removeAttribute('selected');
									}
								}
							}
							break;
					}
				}
			}
		}
		
	} //end foreach
	
	if($populatedValues)
		$doc->documentElement->setAttribute('class', $doc->documentElement->getAttribute('class') . " form_populated");
	
	return $items;
}


/**
 * Replace all form elements with plain text nodes containing their values
 */
function si_form_remove_controls(&$doc){
	$xpath = new DOMXPath($doc);
	
	foreach($xpath->query('//*[@name]') as $input){
		
		$replacement = null;
		$removeLabel = false;
		
		//Find the label
		$label = null;
		if($input->parentNode->nodeName == 'label')
			$label = $input->parentNode;
		else if($input->hasAttribute('id'))
			$label = $xpath->query('//label[@for="' . $input->getAttribute('id') . '"]')->item(0);
		
		switch($input->nodeName){
			//INPUT element
			case 'input':
				switch($input->getAttribute('type')){
					case 'radio':
					case 'checkbox':
						//If not checked, the control and the label are removed;
						if($input->hasAttribute('checked')){
							//If there is a label, then it should be used as the display text
							if($label && $input->getAttribute('value')){
								$replacement = $doc->createElement('abbr');
								$replacement->setAttribute('title', $input->getAttribute('value'));
								$replacement->appendChild($doc->createTextNode($label->textContent));
							}
							else if($label) {
								$replacement = $doc->createTextNode($label->textContent);
							}
							else {
								$replacement = $doc->createTextNode($input->getAttribute('value'));
							}
							
							//Remove the label because it will be replaced with the input value
							if($label){
								if($input->parentNode->isSameNode($label))
									$label->parentNode->replaceChild($input, $label);
								else
									$label->parentNode->removeChild($label);
							}
						}
						//Remove the label if the control is not checked???
						else if($label){
							$label->parentNode->removeChild($label);
						}
						break;
					case 'hidden':
					case 'button':
					case 'add':
					case 'remove':
					case 'delete':
					case 'move-up':
					case 'move-down':
						break;
					default:
						if($input->getAttribute('value')){
							$replacement = $doc->createTextNode($input->getAttribute('value'));
						}
						else {
							$replacement = $doc->createElement('em');
							$replacement->appendChild($doc->createTextNode(__('(Empty)')));
						}
						break;
				}
				break;
			
			//SELECT element
			case 'select':
				$options = $xpath->query('.//option[@selected]', $input);
				if($options->length > 1){
					$replacement = $doc->createElement('ul');
					foreach($options as $option){
						$li = $doc->createElement('li');
						if($option->getAttribute('value')){
							$contents = $doc->createElement('abbr');
							$contents->setAttribute('title', $option->getAttribute('value'));
							$contents->appendChild($doc->createTextNode($option->textContent));
						}
						else
							$contents = $doc->createTextNode($option->textContent);
						$li->appendChild($contents);
						$replacement->appendChild($li);
					}
				}
				else if($options->length == 1){
					$option = $options->item(0);
					if($option->getAttribute('value')){
						$replacement = $doc->createElement('abbr');
						$replacement->setAttribute('title', $option->getAttribute('value'));
						$replacement->appendChild($doc->createTextNode($option->textContent));
					}
					else
						$replacement = $doc->createTextNode($option->textContent);
				}
				break;
			//TEXTAREA element
			case 'textarea':
				if($input->firstChild){
					$replacement = $doc->createElement('pre');
					$replacement->appendChild($doc->createTextNode($input->textContent));
				}
				else {
					$replacement = $doc->createElement('em');
					$replacement->appendChild($doc->createTextNode(__('(Empty)')));
				}
				break;
		}
		
		//Replace the control with the replacement
		if($replacement){
			$input->parentNode->replaceChild($replacement, $input);
			
			//if($label && $label == $input->parentNode){
			//	#$label->parentNode->replaceChild($replacement, $label);
			//	#$replacement->setAttribute('style', 'outline:solid 1px red;');
			//	$input->parentNode->replaceChild($replacement, $input);
			//}
			//else {
			//	#if($label)
			//	#	$label->parentNode->removeChild($label);
			//	$input->parentNode->replaceChild($replacement, $input);
			//}
		}
		//Remove the control completely if no replacement provided
		else {
			$input->parentNode->removeChild($input);
		}
	}
	
	//Remove all BUTTONs
	foreach($xpath->query('//button') as $button){
		$button->parentNode->removeChild($button);
	}
	
	return si_form_serialize($doc, $xpath); //apply_filters('form_markup', $doc->saveXML($doc->documentElement));
}