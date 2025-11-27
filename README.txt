=== WooCommerce Scheduled Discounts Manager ===
Contributors: Tu Nombre
Tags: woocommerce, discounts, sales, badges, scheduled
Requires at least: 5.8
Tested up to: 6.4
Requires PHP: 7.4
Stable tag: 1.3.4
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html

Permite programar descuentos del 10% o 15% en productos seleccionados con insignias personalizables.

== Description ==

WooCommerce Scheduled Discounts Manager es un plugin profesional que te permite:

* Seleccionar manualmente productos de WooCommerce
* Asignarles descuentos del 10% o 15%
* Mostrar insignias PNG personalizadas en los productos
* Programar la activación y desactivación automática por fecha y hora

El plugin utiliza el sistema nativo de precios rebajados de WooCommerce, garantizando compatibilidad total con tu tienda.

== Features ==

* Gestión visual de descuentos desde el panel de administración
* Insignias personalizables en formato PNG
* Programación por fecha y hora
* Compatible con temas personalizados (incluyendo WPBakery)
* Restauración automática de precios al finalizar la campaña
* Sin modificación de archivos del tema

== Installation ==

1. Sube la carpeta `wc-scheduled-discounts` al directorio `/wp-content/plugins/`
2. Activa el plugin desde el menú 'Plugins' en WordPress
3. Ve a WooCommerce > Descuentos Programados para configurar

== Frequently Asked Questions ==

= ¿Puedo usar otros porcentajes de descuento? =

En esta versión solo están disponibles 10% y 15%. Futuras versiones podrían incluir porcentajes personalizados.

= ¿Funciona con productos variables? =

Sí, el plugin es compatible con productos simples y variables.

= ¿Qué pasa si desactivo el plugin? =

Los precios originales se restauran automáticamente.

== Changelog ==

= 1.3.4 =
* Corrección completa y pruebas: Mejoras finales en la gestión de stock
* Ahora se actualizan los metadatos de stock directamente tanto al aplicar como al restaurar
* Mejora en la consistencia: todos los cambios de stock se aplican mediante múltiples métodos
* Corrección en la restauración de stock para productos que no gestionaban inventario previamente

= 1.3.3 =
* Corrección crítica: Mejora en la actualización de cantidades de stock
* Ahora se actualizan los metadatos de stock directamente antes y después de guardar
* Se limpian las cachés de productos para garantizar que los cambios sean visibles inmediatamente
* Se recarga el producto después de actualizar el stock para asegurar consistencia

= 1.3.2 =
* Corrección crítica: Actualización de cantidades de stock ahora funciona correctamente
* Se actualizan los metadatos de stock directamente para garantizar que se guarden
* Mejora en la validación y almacenamiento de cantidades en la configuración
* Corrección para productos variables: las cantidades se aplican correctamente a todas las variaciones

= 1.3.1 =
* Corrección: Mejora en la gestión de inventario para productos variables
* Ahora se habilita correctamente la gestión de inventario al establecer cantidades
* Se actualiza el estado de stock (instock/outofstock) según la cantidad establecida
* Mejora en el respaldo y restauración de configuraciones de stock para productos variables

= 1.3.0 =
* Nueva funcionalidad: Gestión de cantidad de productos
* Los administradores pueden establecer una cantidad específica para cada producto
* La cantidad se actualiza automáticamente cuando la campaña de descuento se activa
* La cantidad original se restaura cuando la campaña finaliza

= 1.0.0 =
* Lanzamiento inicial