<?php if ( ! defined('ABSPATH')) exit; // Exit if accessed directly ?>

<table class="wcmyparcel_settings_table" style="width: auto">
    <tr>
        <td>
            <?php _e('Shipment type', 'woocommerce-myparcel') ?>:<br />
            <?php $parcel_weight = WooCommerce_MyParcel()->export->get_parcel_weight($order); ?>
            <small class="calculated_weight"><?php printf(__('Calculated weight: %s kg', 'woocommerce-myparcel'), number_format($parcel_weight, 3, ',', ' ')); ?></small>
        </td>
        <td>
            <?php
            $name = "myparcel_options[{$order_id}][package_type]";
            printf('<select name="%s" class="package_type">', $name);
            foreach ($package_types as $key => $label) {
                printf('<option value="%s"%s>%s</option>', $key, selected($shipment_options['package_type'], $key, false), $label);
            }
            echo '</select>';
            ?>
        </td>
    </tr>
</table><br>


<?php if ( ! isset($skip_save)): ?>
<div class="wcmp_save_shipment_settings">
    <a class="button save" data-order="<?php echo $order_id; ?>"><?php _e('Save', 'woocommerce-myparcel') ?></a>
    <img src="<?php echo WooCommerce_MyParcel()->plugin_url() . '/assets/img/wpspin_light.gif'; ?>" class="wcmp_spinner waiting" />
</div>
<?php endif ?>
