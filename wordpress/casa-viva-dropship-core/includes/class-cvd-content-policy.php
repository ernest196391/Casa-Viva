<?php
/** EVO-001: fixed policy until an explicitly reviewed release changes it. */
defined( 'ABSPATH' ) || exit;

final class CVD_Content_Policy {
	/** No request, provider credential, option or filter can enable billable work. */
	public static function billable(): WP_Error {
		return new WP_Error( 'evo_budget_zero', 'Presupuesto adicional cero: esta operación de IA está bloqueada.', array( 'status' => 403, 'budget_usd' => 0 ) );
	}
}
