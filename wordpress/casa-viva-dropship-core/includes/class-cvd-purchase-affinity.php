<?php
defined( 'ABSPATH' ) || exit;

/**
 * Offline purchase affinity candidate. No hooks, public endpoint, persistence or scheduler.
 * Only aggregate product IDs leave this service; no customer or order identities are returned.
 */
final class CVD_Purchase_Affinity {
    private const MIN_PAIR = 3;
    private const MAX_ORDERS = 500;
    private const MAX_BASKET = 40;

    /** Return one unique set of parent product IDs per genuinely completed order. */
    public static function basket( WC_Order $order ): array {
        if ( 'completed' !== $order->get_status() || ! class_exists( 'CVD_Canonical_Order_Reader' )
            || $order->get_refunds() ) { return array(); }
        $state = CVD_Canonical_Order_Reader::read( $order );
        if ( 'COMPLETED' !== ( $state['canonical_stage'] ?? '' ) || 'OK' !== ( $state['consistency'] ?? '' )
            || ! empty( $state['incident']['active'] ) ) { return array(); }
        $ids = array();
        foreach ( $order->get_items( 'line_item' ) as $item ) {
            $id = (int) $item->get_product_id();
            if ( $id > 0 && $item->get_quantity() > 0 ) { $ids[ $id ] = $id; }
            if ( count( $ids ) > self::MAX_BASKET ) { return array(); }
        }
        return array_values( $ids );
    }

    /** Pure aggregate. Each input basket represents one distinct order. */
    public static function rank( array $baskets ): array {
        $totals = array();
        $pairs = array();
        foreach ( array_slice( $baskets, 0, self::MAX_ORDERS ) as $basket ) {
            $ids = array_values( array_unique( array_filter( array_map( 'intval', $basket ), static fn( $id ) => $id > 0 ) ) );
            if ( count( $ids ) > self::MAX_BASKET ) { continue; }
            foreach ( $ids as $source ) {
                $totals[ $source ] = ( $totals[ $source ] ?? 0 ) + 1;
                foreach ( $ids as $other ) {
                    if ( $source !== $other ) { $pairs[ $source ][ $other ] = ( $pairs[ $source ][ $other ] ?? 0 ) + 1; }
                }
            }
        }
        $result = array();
        foreach ( $pairs as $source => $others ) {
            $eligible = array();
            foreach ( $others as $other => $count ) {
                if ( $count >= self::MIN_PAIR && 5 * $count >= $totals[ $source ] ) {
                    $eligible[] = array( 'id' => (int) $other, 'support' => $count );
                }
            }
            usort( $eligible, static fn( $a, $b ) => ( $b['support'] <=> $a['support'] ) ?: ( $a['id'] <=> $b['id'] ) );
            if ( $eligible ) { $result[ $source ] = array_column( array_slice( $eligible, 0, 4 ), 'id' ); }
        }
        return $result;
    }

    /** Explicit offline invocation only. Latest completed orders in a 90-day window; bounded sample. */
    public static function snapshot(): array {
        $ids = wc_get_orders( array(
            'status' => 'completed',
            'date_completed' => '>=' . ( time() - 90 * DAY_IN_SECONDS ),
            'orderby' => 'date',
            'order' => 'DESC',
            'limit' => self::MAX_ORDERS + 1,
            'return' => 'ids',
        ) );
        $ids = array_values( array_unique( array_map( 'intval', $ids ) ) );
        $baskets = array();
        $sample = array_slice( $ids, 0, self::MAX_ORDERS );
        foreach ( $sample as $id ) {
            $order = wc_get_order( $id );
            if ( ! $order instanceof WC_Order ) { continue; }
            $basket = self::basket( $order );
            if ( $basket ) { $baskets[] = $basket; }
        }
        return array(
            'scanned' => count( $sample ),
            'eligible' => count( $baskets ),
            'truncated' => count( $ids ) > self::MAX_ORDERS,
            'recommendations' => self::rank( $baskets ),
        );
    }
}
