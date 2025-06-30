<?php
/**
 * File holding the PFMendeleyInputDOI class
 *
 * @file
 */

/**
 * The PFMendeleyInputDOI class.
 */
class PFMendeleyInputDOI extends PFFormInput {

	public static function getName() {
		return 'mendeley_doi';
	}

	public static function getHTML( $cur_value, $input_name, $is_mandatory, $is_disabled, $other_args ) {
		$className = 'mendeley_input_id';
		if ( $is_mandatory ) {
			$className .= ' mandatoryField';
		}

		$doiInputAttrs = [
			'disabled' => 'disabled',
			'class' => $className,
			'style' => 'margin-top:10px;max-width: 400px;',
			'placeholder' => 'Document ID (Can be auto populated on selecting title in above field)',
			'size' => '50',
		];

		$spanClass = 'inputSpan';
		if ( $is_mandatory ) {
			$spanClass .= ' mandatoryFieldSpan';
		}

		return Html::rawElement(
			'span',
			[ 'class' => $spanClass ],
			Html::input(
				$input_name,
				$cur_value,
				'text',
				$doiInputAttrs
			)
		);
	}

	/**
	 * Returns the HTML code to be included in the output page for this input.
	 */
	public function getHtmlText() {
		return self::getHTML(
			$this->mCurrentValue,
			$this->mInputName,
			$this->mIsMandatory,
			$this->mIsDisabled,
			$this->mOtherArgs
		);
	}
}
