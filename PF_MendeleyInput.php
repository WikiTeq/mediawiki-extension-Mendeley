<?php
/**
 * File holding the PFMendeleyInput class
 *
 * @file
 */

/**
 * The PFMendeleyInput class.
 */
class PFMendeleyInput extends PFFormInput {

	public static function getName() {
		return 'mendeley_article';
	}

	public static function getHTML( $cur_value, $input_name, $is_mandatory, $is_disabled, $other_args ) {
		global $wgOut;

		$className = 'mendeley_input';
		if ( $is_mandatory ) {
			$className .= ' mandatoryField';
		}

		$wgOut->addModules( 'ext.mendeley.main' );
		$queryInputAttrs = [
			'class' => $className,
			'style' => 'max-width: 400px;',
			'placeholder' => 'Search Document title or author name',
			'size' => '50'
		];

		$spanClass = 'inputSpan';
		if ( $is_mandatory ) {
			$spanClass .= ' mandatoryFieldSpan';
		}

		return Html::rawElement(
			'span',
			[ 'class' => $spanClass ],
			Html::input( $input_name, $cur_value, 'text', $queryInputAttrs )
		);
	}

	/**
	 * Returns the HTML code to be included in the output page for this input.
	 * @return string
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
