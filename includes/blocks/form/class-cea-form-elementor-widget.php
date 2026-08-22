<?php
/**
 * The cea_form Elementor widget.
 *
 * Only ever require()'d from CEA_Form_Block::elementor_widget_class(),
 * itself only ever called from CEA_Block_Registry::init_elementor(),
 * itself only ever invoked from Elementor's own `elementor/widgets/register`
 * hook — so by the time this file loads, \Elementor\Widget_Base is
 * guaranteed to already exist. See docs/BLOCKS-PLAN.md, section 7.
 *
 * @package CEA_Plugin
 */

defined( 'ABSPATH' ) || exit;

/**
 * Displays a published CEA form inside an Elementor-built page.
 *
 * render() delegates to CEA_Form_Block::render() — the exact same
 * implementation the Gutenberg block uses — so front-end markup, CSS
 * classes, and asset enqueuing are identical between the two builders.
 * See docs/BLOCKS-PLAN.md, sections 1 and 7.
 */
final class CEA_Form_Elementor_Widget extends \Elementor\Widget_Base {

	/**
	 * @return string
	 */
	public function get_name() {
		return 'cea_form';
	}

	/**
	 * @return string
	 */
	public function get_title() {
		return __( 'CEA Form', 'cea-plugin' );
	}

	/**
	 * @return string
	 */
	public function get_icon() {
		return 'eicon-mail';
	}

	/**
	 * @return array
	 */
	public function get_categories() {
		return array( 'cea' );
	}

	/**
	 * @return array
	 */
	public function get_keywords() {
		return array( 'form', 'cea', 'contact' );
	}

	/**
	 * Registers the widget's controls.
	 *
	 * @return void
	 */
	protected function register_controls() {
		$this->start_controls_section(
			'section_form',
			array(
				'label' => __( 'Form', 'cea-plugin' ),
			)
		);

		$this->add_control(
			'form_id',
			array(
				'label'       => __( 'Form', 'cea-plugin' ),
				'type'        => \Elementor\Controls_Manager::SELECT2,
				'options'     => $this->get_form_options(),
				'default'     => 0,
				'label_block' => true,
			)
		);

		$this->end_controls_section();
	}

	/**
	 * Builds the { id => title } options list for the form_id control,
	 * from the same CEA_Form_Picker the Gutenberg block's REST route
	 * reads — a single shared source for "which forms can be picked" —
	 * see docs/BLOCKS-PLAN.md, section 3.
	 *
	 * @return array<int, string>
	 */
	private function get_form_options() {
		$options = array( 0 => __( 'Select a form…', 'cea-plugin' ) );

		foreach ( CEA_Form_Picker::get_choices() as $choice ) {
			$options[ $choice['id'] ] = $choice['title'];
		}

		return $options;
	}

	/**
	 * Renders the widget's front-end markup.
	 *
	 * @return void
	 */
	protected function render() {
		$form_block = new CEA_Form_Block();

		// CEA_Form_Block::render() already returns safe, pre-escaped
		// markup (the same output CEA_Form_Renderer::render_shortcode()
		// produces for the shortcode and the Gutenberg block).
		echo $form_block->render( array( 'formId' => $this->get_settings_for_display( 'form_id' ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}
}
