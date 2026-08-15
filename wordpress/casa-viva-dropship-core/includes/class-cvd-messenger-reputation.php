<?php

defined( 'ABSPATH' ) || exit;

/** Reputación verificable y prioridad justa para mensajeros. */
final class CVD_Messenger_Reputation {
	public static function register(): void {
		add_action( 'woocommerce_order_status_completed', array( __CLASS__, 'invalidate_order_messenger' ), 60 );
		add_action( 'woocommerce_order_status_cancelled', array( __CLASS__, 'invalidate_order_messenger' ), 60 );
		add_action( 'woocommerce_order_status_refunded', array( __CLASS__, 'invalidate_order_messenger' ), 60 );
	}

	public static function invalidate_order_messenger( int $order_id ): void {
		$order = wc_get_order( $order_id ); if ( ! $order ) { return; }
		$user_id = absint( $order->get_meta( '_cvd_messenger_user_id', true ) );
		if ( $user_id ) { self::invalidate( $user_id ); }
	}

	public static function invalidate( int $user_id ): void { delete_transient( 'cvd_messenger_metrics_' . $user_id ); }

	public static function metrics( int $user_id ): array {
		$cached = get_transient( 'cvd_messenger_metrics_' . $user_id ); if ( is_array( $cached ) ) { return $cached; }
		$orders = wc_get_orders( array( 'limit'=>200, 'orderby'=>'date', 'order'=>'DESC', 'meta_key'=>'_cvd_messenger_user_id', 'meta_value'=>$user_id ) );
		$assigned=0; $completed=0; $incidents=0; $speed_total=0; $speed_count=0; $active=0;
		foreach ( $orders as $order ) {
			$status=CVD_Delivery::status($order); if ( 'cancelled' === $status ) { continue; } $assigned++;
			if ( 'closed' === $status ) { $completed++; }
			if ( in_array($status,array('incident','failed','returned'),true) ) { $incidents++; }
			if ( ! in_array($status,array('closed','failed','returned','cancelled'),true) ) { $active++; }
			$offered=strtotime((string)$order->get_meta('_cvd_delivery_offered_at',true)); $accepted=strtotime((string)$order->get_meta('_cvd_delivery_accepted_at',true));
			if($offered&&$accepted&&$accepted>=$offered){$speed_total+=min(3600,$accepted-$offered);$speed_count++;}
		}
		$rating_count=absint(get_user_meta($user_id,'_cvd_rating_count',true)); $rating_avg=(float)get_user_meta($user_id,'_cvd_rating_average',true);
		$result=array(
			'assigned'=>$assigned,'completed'=>$completed,'active'=>$active,'incidents'=>$incidents,
			'completion_rate'=>$assigned ? round($completed/$assigned*100,1) : 80.0,
			'incident_rate'=>$assigned ? round($incidents/$assigned*100,1) : 0.0,
			'rating_count'=>$rating_count,'rating_average'=>$rating_count ? $rating_avg : 4.0,
			'acceptance_seconds'=>$speed_count ? (int)round($speed_total/$speed_count) : 600,
			'last_accepted_at'=>absint(get_user_meta($user_id,'_cvd_last_delivery_accepted_at',true)),
		);
		set_transient('cvd_messenger_metrics_'.$user_id,$result,10*MINUTE_IN_SECONDS); return $result;
	}

	public static function score( WP_User $user, WC_Order $order ): array {
		$m=self::metrics($user->ID); $weights=self::weights();
		$zone=CVD_Delivery::zone_matches($user,$order)?100:35;
		$rating=max(0,min(100,(($m['rating_average']-1)/4)*100));
		$completion=max(0,min(100,$m['completion_rate']-$m['incident_rate']));
		$speed=max(0,min(100,100-($m['acceptance_seconds']/18)));
		$fairness=max(0,100-min(100,$m['active']*30));
		$total=$zone*$weights['zone']+$rating*$weights['rating']+$completion*$weights['completion']+$speed*$weights['speed']+$fairness*$weights['fairness'];
		$sum=array_sum($weights) ?: 1; return array('total'=>round($total/$sum,1),'zone'=>$zone,'rating'=>$rating,'completion'=>$completion,'speed'=>$speed,'fairness'=>$fairness,'metrics'=>$m);
	}

	public static function rank( array $users, WC_Order $order ): array {
		usort($users,static function(WP_User $a,WP_User $b)use($order):int{$sa=self::score($a,$order);$sb=self::score($b,$order);$score=$sb['total']<=>$sa['total'];if($score){return $score;}$la=(int)$sa['metrics']['last_accepted_at'];$lb=(int)$sb['metrics']['last_accepted_at'];if($la!==$lb){return $la<=>$lb;}return $a->ID<=>$b->ID;});
		return $users;
	}

	public static function weights(): array {
		$defaults=array('zone'=>30,'rating'=>25,'completion'=>20,'speed'=>15,'fairness'=>10); $out=array();
		foreach($defaults as $key=>$default){$out[$key]=max(0,(int)get_option('cvd_dispatch_'.$key.'_weight',$default));} return $out;
	}

	public static function label( float $score ): string { return $score>=85?'Excelente':($score>=70?'Muy buena':($score>=55?'En desarrollo':'Necesita revisión')); }
}
