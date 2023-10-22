<?php
/*
Plugin Name: Mi Plugin de Cotizaciones
Description: Plugin personalizado para gestionar cotizaciones entre distribuidores y vendedores.
Version: 1.0
Author: Tu nombre
*/

global $cotizaciones_db_version;
$cotizaciones_db_version = '1.0';

// 1. Crear tabla al activar el plugin
function crear_tabla_cotizaciones() {
    global $wpdb;
    global $cotizaciones_db_version;

    $table_name = $wpdb->prefix . 'cotizaciones';
    $charset_collate = $wpdb->get_charset_collate();

$sql = "CREATE TABLE $table_name (
    id mediumint(9) NOT NULL AUTO_INCREMENT,
    numero_orden varchar(100) NOT NULL UNIQUE,
    time datetime DEFAULT '0000-00-00 00:00:00' NOT NULL,
    distribuidor_id mediumint(9) NOT NULL,
    vendedor_id mediumint(9) NOT NULL,
    producto_name text NOT NULL,
    cantidad int(11) NOT NULL,
    estado varchar(50) DEFAULT 'Pendiente de pago' NOT NULL,
    nombre_apellido text,
    telefono text,
    correo text,
    direccion text,
    nota text,
    productos TEXT,
    PRIMARY KEY  (id)
) $charset_collate;";

    require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
    dbDelta( $sql );

    add_option( 'cotizaciones_db_version', $cotizaciones_db_version );
}

register_activation_hook( __FILE__, 'crear_tabla_cotizaciones' );

// 2. Añadir la sección en el panel de control
function agregar_menu_cotizaciones() {
    // Asegurándonos de que solo los distribuidores y administradores puedan ver este menú.
    if (current_user_can('manage_quotes')) {
        add_menu_page(
            'Cotizaciones',           // Título de la página
            'Cotizaciones',           // Título del menú
            'manage_quotes',          // Capacidad requerida
            'gestion-cotizaciones',   // Slug del menú
            'mostrar_cotizaciones'    // Función que muestra el contenido
        );
    }
}

add_action( 'admin_menu', 'agregar_menu_cotizaciones' );

if (!class_exists('WP_List_Table')) {
    require_once(ABSPATH . 'wp-admin/includes/class-wp-list-table.php');
}

class Cotizaciones_Enviadas_List extends WP_List_Table {

    function __construct() {
        parent::__construct(array(
            'singular' => 'cotizacion',
            'plural'   => 'cotizaciones',
            'ajax'     => false
        ));
    }

    function get_columns() {
        return array(
            'producto_name'     => 'Producto',
            'cantidad'          => 'Cantidad',
            'nombre_apellido'   => 'Nombre y Apellido',
            'telefono'          => 'Teléfono',
            'correo'            => 'Correo',
            'direccion'         => 'Dirección',
            'nota'              => 'Comentarios',
            'time'              => 'Fecha',
            'estado'            => 'Estado',
            'actions'           => 'Acciones'
        );
    }

function column_default($item, $column_name) {
    switch ($column_name) {
        case 'producto_name':
            $productos = json_decode($item->productos, true);
            $nombres = array_map(function($producto) {
                return esc_html($producto['producto_name']);
            }, $productos);
            return implode(', ', $nombres);
        case 'cantidad':
            $productos = json_decode($item->productos, true);
            $cantidades = array_map(function($producto) {
                return esc_html($producto['cantidad']);
            }, $productos);
            return implode(', ', $cantidades);
        case 'nombre_apellido':
        case 'telefono':
        case 'correo':
        case 'direccion':
        case 'nota':
        case 'time':
            return esc_html($item->$column_name);
        case 'estado':
            $color = "";
            if ($item->estado == "Pendiente de pago") $color = "orange";
            if ($item->estado == "Pagada") $color = "green";
            if ($item->estado == "Cancelada") $color = "red";
            return '<span style="color:' . $color . '">' . esc_html($item->estado) . '</span>
                    <select name="estado_cotizacion[' . $item->id . ']">
                        <option value="Pendiente de pago"' . ($item->estado == "Pendiente de pago" ? "selected" : "") . '>Pendiente de pago</option>
                        <option value="Pagada"' . ($item->estado == "Pagada" ? "selected" : "") . '>Pagada</option>
                        <option value="Cancelada"' . ($item->estado == "Cancelada" ? "selected" : "") . '>Cancelada</option>
                    </select>';
        default:
            return print_r($item, true);
    }
}

    public function prepare_items() {
        $this->_column_headers = array(
            $this->get_columns(), 
            array(), 
            $this->get_sortable_columns()
        );

        global $wpdb;
        $current_user_id = get_current_user_id();
        $table_name = $wpdb->prefix . 'cotizaciones';

        $per_page = 10;
        $current_page = max(1, isset($_GET['paged']) ? intval($_GET['paged']) : 1);
        $offset = ($current_page - 1) * $per_page;

        $search = (isset($_REQUEST['s'])) ? sanitize_text_field($_REQUEST['s']) : '';
        $query = "SELECT * FROM $table_name WHERE vendedor_id = %d"; // Aquí cambiamos para que el vendedor vea solo sus cotizaciones
        $params = array($current_user_id);

        if ($search) {
            $query .= " AND (producto_name LIKE %s OR nombre_apellido LIKE %s OR correo LIKE %s OR direccion LIKE %s)";
            array_push($params, '%' . $wpdb->esc_like($search) . '%', '%' . $wpdb->esc_like($search) . '%', '%' . $wpdb->esc_like($search) . '%', '%' . $wpdb->esc_like($search) . '%');
        }

        $query .= " LIMIT %d OFFSET %d";
        array_push($params, $per_page, $offset);

        $cotizaciones = $wpdb->get_results($wpdb->prepare($query, $params));

        $this->items = $cotizaciones;

        $total_items = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $table_name WHERE vendedor_id = %d", $current_user_id));
        $this->set_pagination_args(array(
            'total_items' => $total_items,
            'per_page'    => $per_page,
            'total_pages' => ceil($total_items / $per_page)
        ));
    }
}

function get_product_id_by_name($product_name) {
    global $wpdb;
    $product_id = $wpdb->get_var($wpdb->prepare("SELECT ID FROM $wpdb->posts WHERE post_title = %s AND post_type = 'product'", $product_name));
    return $product_id ? intval($product_id) : null;
}


// Funciones específicas para obtener los productos, cantidades y estados
function get_productos($item) {
    $productos = json_decode($item->productos, true);
    $nombres = array_map(function($producto) {
        return esc_html($producto['producto_name']);
    }, $productos);
    return implode(', ', $nombres);
}

function get_cantidades($item) {
    $productos = json_decode($item->productos, true);
    $cantidades = array_map(function($producto) {
        return esc_html($producto['cantidad']);
    }, $productos);
    return implode(', ', $cantidades);
}

function get_productos_con_cantidad($item) {
    $productos = json_decode($item->productos, true);
    $productos_con_cantidad = array_map(function($producto) {
        $image = '';
        $nombre_producto = isset($producto['producto_name']) ? $producto['producto_name'] : 'Producto desconocido';

        // Obtener el ID del producto por su nombre
        $product_id = get_product_id_by_name($nombre_producto);

        if ($product_id) {
            $wc_producto = wc_get_product($product_id);
            if ($wc_producto) {
                $image = $wc_producto->get_image();
                $nombre_producto = $wc_producto->get_name();
            }
        }

        return '<div class="producto-item">' 
            . (!empty($image) ? '<div class="producto-img">' . $image . '</div>' : '')
            . '<div class="producto-info">'
            . '<span>' . esc_html($producto['cantidad']) . 'x</span>' 
            . esc_html($nombre_producto) 
            . '</div>'
            . '</div>';

    }, $productos);
    return '<div class="productos-list">' . implode('', $productos_con_cantidad) . '</div>';
}



function get_estado($item) {
    $color = "";
    if ($item->estado == "Pendiente de pago") $color = "orange";
    if ($item->estado == "Pagada") $color = "green";
    if ($item->estado == "Cancelada") $color = "red";
    return '<span style="color:' . $color . '">' . esc_html($item->estado) . '</span>
            <select name="estado_cotizacion[' . $item->id . ']">
                <option value="Pendiente de pago"' . ($item->estado == "Pendiente de pago" ? "selected" : "") . '>Pendiente de pago</option>
                <option value="Pagada"' . ($item->estado == "Pagada" ? "selected" : "") . '>Pagada</option>
                <option value="Cancelada"' . ($item->estado == "Cancelada" ? "selected" : "") . '>Cancelada</option>
            </select>';
}

// Función mostrar_cotizaciones actualizada
function mostrar_cotizaciones() {
    // Crear una instancia de la lista de cotizaciones
    $cotizacionesList = new Cotizaciones_List();

    // Acciones al enviar el formulario
    if (isset($_POST['actualizar_estado']) && isset($_POST['estado_cotizacion'])) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'cotizaciones';
        $id = intval($_POST['actualizar_estado']);
        $nuevo_estado = sanitize_text_field($_POST['estado_cotizacion'][$id]);
        $wpdb->update(
            $table_name,
            array('estado' => $nuevo_estado),
            array('id' => $id)
        );
    }

    // Preparar los elementos (recuperar datos, configurar paginación, etc.)
    $cotizacionesList->prepare_items();

    echo '<div class="wrap">';
    echo '<h2>Cotizaciones</h2>';
    // Agregar manualmente la caja de búsqueda
echo '<div class="search-container">'; // Contenedor para la barra de búsqueda
echo '<form method="get" class="search-form">';
echo '<input type="hidden" name="page" value="' . esc_attr($_REQUEST['page']) . '">';
$cotizacionesList->search_box('Buscar', 'search_id');
echo '</form>';
echo '</div>'; 
echo '<div style="clear:both;"></div>'; // Limpia cualquier flotación
    
    // Mostrar cotizaciones como tarjetas
    echo '<form method="post" class="cotizaciones-form">';
    echo '<div class="cotizaciones-container">';
    foreach ($cotizacionesList->items as $cotizacion) {
        echo '<div class="cotizacion-card">';
        echo '<strong>Número de Orden: </strong>' . esc_html($cotizacion->numero_orden) . '<br>';
        echo '<strong>Producto: </strong>' . get_productos_con_cantidad($cotizacion) . '<br>';
        echo '<strong>Cantidad: </strong>' . get_cantidades($cotizacion) . '<br>';
        echo '<strong>Nombre: </strong>' . esc_html($cotizacion->nombre_apellido) . '<br>';
        echo '<strong>Teléfono: </strong>' . esc_html($cotizacion->telefono) . '<br>';
        echo '<strong>Correo: </strong>' . esc_html($cotizacion->correo) . '<br>';
        echo '<strong>Dirección: </strong>' . esc_html($cotizacion->direccion) . '<br>';
        echo '<strong>Nota: </strong>' . esc_html($cotizacion->nota) . '<br>';
        echo '<strong>Estado: </strong>' . get_estado($cotizacion) . '<br>';
        echo '<div class="cotizacion-actions">';
        echo '<button type="submit" name="actualizar_estado" value="' . $cotizacion->id . '">Actualizar</button>';
        echo '</div>';
        echo '</div>';  // Fin de la tarjeta de cotización
    }
    
    echo '</div>';
    echo '</form>';

    echo '</div>';  // Fin del wrap
}




// 1. Creación de Roles
function corregir_rol_distribuidor() {
    $distribuidor = get_role('distribuidor');
    if (!$distribuidor) {
        // Si el rol de distribuidor no existe, créalo
        $distribuidor = add_role('distribuidor', 'distribuidor', array('read' => true));
    }
    // Asignar la capacidad manage_quotes al rol de distribuidor
    $distribuidor->add_cap('manage_quotes');
}

register_activation_hook(__FILE__, 'corregir_rol_distribuidor');

function agregar_roles() {
    add_role('distribuidor', 'distribuidor', array(
        'read' => true,
        'manage_quotes' => true
    ));
}
register_activation_hook(__FILE__, 'agregar_roles');

// 2. Asignación de vendedores a distribuidores
function custom_user_profile_fields($user) {
    if (current_user_can('administrator')) {
        $distribuidores = get_users(array('role' => 'distribuidor'));
        ?>
        <h3>distribuidor Asignado</h3>
        <select name="distribuidor_asignado" id="distribuidor_asignado">
            <option value="">-- Seleccionar --</option>
            <?php foreach($distribuidores as $distribuidor) : ?>
                <option value="<?php echo $distribuidor->ID; ?>" <?php selected(get_the_author_meta('distribuidor_asignado', $user->ID), $distribuidor->ID); ?>>
                    <?php echo $distribuidor->display_name; ?>
                </option>
            <?php endforeach; ?>
        </select>
        <?php
    }
}
add_action('show_user_profile', 'custom_user_profile_fields');
add_action('edit_user_profile', 'custom_user_profile_fields');

function guardar_distribuidor_asignado($user_id) {
    if (current_user_can('edit_user', $user_id)) {
        update_user_meta($user_id, 'distribuidor_asignado', $_POST['distribuidor_asignado']);
    }
}
add_action('personal_options_update', 'guardar_distribuidor_asignado');
add_action('edit_user_profile_update', 'guardar_distribuidor_asignado');



// 1. Añadir una columna personalizada en la tabla de usuarios
function agregar_columna_distribuidor($columns) {
    $columns['distribuidor_asignado'] = 'distribuidor Asignado';
    return $columns;
}
add_filter('manage_users_columns', 'agregar_columna_distribuidor');

// 2. Rellenar la columna con la información del distribuidor asignado
function mostrar_distribuidor_asignado($value, $column_name, $user_id) {
    if ('distribuidor_asignado' == $column_name) {
        $distribuidor_id = get_user_meta($user_id, 'distribuidor_asignado', true);
        if ($distribuidor_id) {
            $distribuidor = get_userdata($distribuidor_id);
            return $distribuidor->display_name;
        } else {
            return 'No asignado';
        }
    }
    return $value;
}
add_filter('manage_users_custom_column', 'mostrar_distribuidor_asignado', 10, 3);


// 3. Cotizaciones
function mostrar_solicitud_cotizacion() {
    $distribuidor_asignado = get_the_author_meta('distribuidor_asignado', get_current_user_id());
    if ($distribuidor_asignado) {
        $url_cotizacion = get_site_url() . '/agregar-cotizacion?product_id=' . get_the_ID(); // Cambiamos el slug también
        echo '<a href="' . $url_cotizacion . '" class="button alt">Agregar a Cotización</a>';
    }
}

function iniciar_sesion() {
    if (!session_id()) {
        session_start();
    }
    if (!isset($_SESSION['cotizacion_carrito'])) {
        $_SESSION['cotizacion_carrito'] = array();
    }
}
add_action('init', 'iniciar_sesion', 1);

function agregar_a_cotizacion() {
    if(is_page('agregar-cotizacion')) {
        $product_id = isset($_GET['product_id']) ? intval($_GET['product_id']) : null;
        if($product_id) {
            // Agregar producto al carrito
            if(!in_array($product_id, $_SESSION['cotizacion_carrito'])) {
                $_SESSION['cotizacion_carrito'][] = $product_id;
            }
            wp_redirect(get_site_url() . '/carrito-cotizacion'); // Redireccionar al carrito después de agregar
            exit;
        }
    }
}
add_action('template_redirect', 'agregar_a_cotizacion');

function mostrar_carrito_cotizacion() {
    if(is_page('carrito-cotizacion')) {
        echo '<h2>Productos para Cotización</h2>';
        echo '<div class="carrito-cotizacion-lista">';
        
        foreach($_SESSION['cotizacion_carrito'] as $key => $product_id) {
            $producto = wc_get_product($product_id);
            if($producto) {
                // Muestra la imagen del producto en miniatura
                $image = $producto->get_image();
                
echo '<div class="carrito-item">';
echo '<div class="carrito-image">' . $image . '</div>';
echo '<div class="carrito-name">' . esc_html($producto->get_name()) . '</div>';
echo '<div class="carrito-actions"><a href="?remove=' . $key . '" class="remove-product">Eliminar</a></div>';
echo '</div>';

            }
        }
        
        echo '</div>';
        echo '<a href="' . get_site_url() . '/solicitud-cotizacion" class="button alt">Solicitar Cotización</a>';
    }
}
add_action('the_content', 'mostrar_carrito_cotizacion');

// Función para manejar la eliminación de productos del carrito
function handle_remove_product_from_cart() {
    if(isset($_GET['remove'])) {
        $key = intval($_GET['remove']);
        unset($_SESSION['cotizacion_carrito'][$key]);
        // Redirige de nuevo a la página del carrito para evitar problemas con la recarga
        wp_redirect(get_site_url() . '/carrito-cotizacion');
        exit;
    }
}
add_action('init', 'handle_remove_product_from_cart');



function mostrar_formulario_cotizacion() {
    global $wpdb;

    if (is_page('solicitud-cotizacion')) {
        
        // Verificar si hay productos en el carrito
        if (empty($_SESSION['cotizacion_carrito'])) {
            echo 'No hay productos para cotizar.';
            return;
        }

        $errores = [];
        $success = false;

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            // Validar nota (opcional pero limitada en longitud)
            $nota = isset($_POST['nota']) ? sanitize_textarea_field($_POST['nota']) : '';
            if (strlen($nota) > 500) {
                $errores[] = 'La nota es demasiado larga. Limitado a 500 caracteres.';
            }

            if (empty($errores)) {
                // Obtener ID del distribuidor asignado
                $distribuidor_asignado = get_the_author_meta('distribuidor_asignado', get_current_user_id());
                $tabla_cotizaciones = $wpdb->prefix . 'cotizaciones';
                
                $productos_array = [];
                foreach ($_SESSION['cotizacion_carrito'] as $product_id) {
                    $producto = wc_get_product($product_id);
                    if ($producto) {
                        $productos_array[] = array(
                            'producto_id' => $product_id,
                            'producto_name' => $producto->get_name(),
                            'cantidad' => intval($_POST['cantidad_' . $product_id])
                        );
                    }
                }

                // Datos para guardar en la base de datos
                $data = array(
                    'time' => current_time('mysql'),
                    'distribuidor_id' => $distribuidor_asignado,
                    'vendedor_id' => get_current_user_id(),
                    'productos' => json_encode($productos_array),
                    'nombre_apellido' => sanitize_text_field($_POST['nombre_apellido']),
                    'telefono' => sanitize_text_field($_POST['telefono']),
                    'correo' => sanitize_email($_POST['correo']),
                    'direccion' => sanitize_text_field($_POST['direccion']),
                    'nota' => $nota
                );

                $format = array('%s', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s');
$result = $wpdb->insert($tabla_cotizaciones, $data, $format);

if ($result) {
    // Después de insertar la nueva cotización en la base de datos...
    $ultimo_id = $wpdb->insert_id;
    $numero_orden = "ORD" . str_pad($ultimo_id, 6, "0", STR_PAD_LEFT);

    // Actualiza el número de orden en la base de datos
    $wpdb->update($tabla_cotizaciones, array('numero_orden' => $numero_orden), array('id' => $ultimo_id));

    $success = true;
    enviar_notificacion_distribuidor($distribuidor_asignado);
    $_SESSION['cotizacion_carrito'] = []; // Vaciar el carrito
} else {
    $last_error = $wpdb->last_error;
    $errores[] = "Hubo un problema al guardar la cotización. Detalles: $last_error";
}

            }
        }

        // Mostrar mensajes de error o éxito
        if (!empty($errores)) {
            echo '<div class="error"><ul>';
            foreach ($errores as $error) {
                echo '<li>' . esc_html($error) . '</li>';
            }
            echo '</ul></div>';
        }

        if ($success) {
            echo '<div class="success">Tu solicitud de cotización ha sido enviada con éxito.</div>';
            return;
        }

        // Mostrar el formulario
        echo '<form method="post" action="">';
        foreach ($_SESSION['cotizacion_carrito'] as $product_id) {
            $producto = wc_get_product($product_id);
            if ($producto) {
                echo '<input type="hidden" name="producto_id[]" value="' . esc_attr($product_id) . '">';
                echo 'Producto: ' . esc_html($producto->get_name()) . '<br>';
                echo 'Cantidad: <input type="number" name="cantidad_' . $product_id . '" required><br>';
            }
        }
        echo '---Mis datos de cliente---<br>';
        echo 'Nombre y apellido: <input type="text" name="nombre_apellido" required><br>';
        echo 'Teléfono con lada: <input type="text" name="telefono" required><br>';
        echo 'Correo electrónico: <input type="email" name="correo" required><br>';
        echo 'Dirección: <input type="text" name="direccion" required><br>';
        echo 'Comentarios adicionales: <textarea name="nota" maxlength="500"></textarea><br>';
        echo '<input type="submit" value="Enviar Cotización">';
        echo '</form>';
    }
}


add_action('the_content', 'mostrar_formulario_cotizacion');



// 4. Notificaciones
function enviar_notificacion_distribuidor($distribuidor_asignado) {
    $distribuidor_email = get_the_author_meta('user_email', $distribuidor_asignado);
    $subject = "Nueva solicitud de cotización";
    $message = "Tienes una nueva solicitud de cotización pendiente.";
    wp_mail($distribuidor_email, $subject, $message);
}

function es_cliente() {
    // Verificar si el usuario ha iniciado sesión
    if (is_user_logged_in()) {
        $user = wp_get_current_user();
        // Comprobar si el usuario tiene el rol de 'customer'
        return in_array('customer', $user->roles);
    }
    // Retornar falso si el usuario no ha iniciado sesión
    return false;
}

function restablecer_capacidades_distribuidor() {
    $role = get_role('distribuidor');

    // Asegurar que el rol distribuidor existe
    if (!$role) return;

    // Añadir capacidades básicas para acceder al escritorio
    $role->add_cap('read');
    $role->add_cap('edit_posts');
}
add_action('init', 'restablecer_capacidades_distribuidor');

// ... [Todo el código existente de tu plugin] ...

// Paso 1: Agregar una nueva sección en el panel de control para vendedores

function agregar_menu_cotizaciones_enviadas() {
    if (current_user_can('view_sent_quotes')) {  // Verificamos que el usuario tenga la capacidad 'view_sent_quotes'
        add_menu_page(
            'Cotizaciones Enviadas',
            'Cotizaciones Enviadas',
            'view_sent_quotes',  // Usamos la capacidad personalizada
            'gestion-cotizaciones-enviadas',
            'mostrar_cotizaciones_enviadas'
        );
    }
}
add_action( 'admin_menu', 'agregar_menu_cotizaciones_enviadas' );

// Paso 2: Crear la nueva clase Cotizaciones_Enviadas_List

class Cotizaciones_List extends WP_List_Table {

    function __construct() {
        parent::__construct(array(
            'singular' => 'cotizacion',
            'plural'   => 'cotizaciones',
            'ajax'     => false
        ));
    }

    function get_columns() {
        return array(
        'numero_orden'       => 'Número de Orden',
            'producto_name'     => 'Producto',
            'cantidad'          => 'Cantidad',
            'nombre_apellido'   => 'Nombre y Apellido',
            'telefono'          => 'Teléfono',
            'correo'            => 'Correo',
            'direccion'         => 'Dirección',
            'nota'              => 'Comentarios',
            'time'              => 'Fecha',
            'estado'            => 'Estado',
            'actions'           => 'Acciones'
        );
    }

function column_default($item, $column_name) {
    // Iniciar la tarjeta
    $html = '<div class="cotizacion-panel">';

    // Número de Orden en la cabecera
    $html .= '<div class="panel-header">Número de Orden: ' . esc_html($item->numero_orden) . '</div>';

    // Cuerpo de la tarjeta
    $html .= '<div class="panel-body">';

    switch ($column_name) {
        case 'producto_name':
            $productos = json_decode($item->productos, true);
            $nombres = array_map(function($producto) {
                return esc_html($producto['producto_name']);
            }, $productos);
            $html .= '<strong>Producto:</strong> ' . implode(', ', $nombres) . '<br>';
            break;

        case 'cantidad':
            $productos = json_decode($item->productos, true);
            $cantidades = array_map(function($producto) {
                return esc_html($producto['cantidad']);
            }, $productos);
            $html .= '<strong>Cantidad:</strong> ' . implode(', ', $cantidades) . '<br>';
            break;

        case 'nombre_apellido':
            $html .= '<strong>Nombre y Apellido:</strong> ' . esc_html($item->nombre_apellido) . '<br>';
            break;

        case 'telefono':
            $html .= '<strong>Teléfono:</strong> ' . esc_html($item->telefono) . '<br>';
            break;

        case 'correo':
            $html .= '<strong>Correo:</strong> ' . esc_html($item->correo) . '<br>';
            break;

        case 'direccion':
            $html .= '<strong>Dirección:</strong> ' . esc_html($item->direccion) . '<br>';
            break;

        case 'nota':
            $html .= '<strong>Comentarios:</strong> ' . esc_html($item->nota) . '<br>';
            break;

        case 'estado':
            $color = "";
            if ($item->estado == "Pendiente de pago") $color = "orange";
            if ($item->estado == "Pagada") $color = "green";
            if ($item->estado == "Cancelada") $color = "red";
            $html .= '<strong>Estado:</strong> <span style="color:' . $color . '">' . esc_html($item->estado) . '</span><br>';
            $html .= '<select name="estado_cotizacion[' . $item->id . ']">
                        <option value="Pendiente de pago"' . ($item->estado == "Pendiente de pago" ? "selected" : "") . '>Pendiente de pago</option>
                        <option value="Pagada"' . ($item->estado == "Pagada" ? "selected" : "") . '>Pagada</option>
                        <option value="Cancelada"' . ($item->estado == "Cancelada" ? "selected" : "") . '>Cancelada</option>
                    </select><br>';
            break;

        default:
            $html .= print_r($item, true);
            break;
    }

    $html .= '</div>';  // Fin del cuerpo del panel

    // Pie de la tarjeta
    $html .= '<div class="panel-footer">';
    $html .= '<button type="submit" name="actualizar_estado" value="' . $item->id . '">Actualizar</button>';
    $html .= '</div>';  // Fin del pie de página del panel

    // Finalizar la tarjeta
    $html .= '</div>';  // Fin del panel

    return $html;
}



    public function prepare_items() {
        $this->_column_headers = array(
            $this->get_columns(), 
            array(), 
            $this->get_sortable_columns()
        );

        global $wpdb;
        $current_user_id = get_current_user_id();
        $table_name = $wpdb->prefix . 'cotizaciones';

        $per_page = 10;
        $current_page = max(1, isset($_GET['paged']) ? intval($_GET['paged']) : 1);
        $offset = ($current_page - 1) * $per_page;

        $search = (isset($_REQUEST['s'])) ? sanitize_text_field($_REQUEST['s']) : '';
        $query = "SELECT * FROM $table_name WHERE distribuidor_id = %d";
        $params = array($current_user_id);

        if ($search) {
            $query .= " AND (producto_name LIKE %s OR nombre_apellido LIKE %s OR correo LIKE %s OR direccion LIKE %s)";
            array_push($params, '%' . $wpdb->esc_like($search) . '%', '%' . $wpdb->esc_like($search) . '%', '%' . $wpdb->esc_like($search) . '%', '%' . $wpdb->esc_like($search) . '%');
        }

        $query .= " LIMIT %d OFFSET %d";
        array_push($params, $per_page, $offset);

        $cotizaciones = $wpdb->get_results($wpdb->prepare($query, $params));

        $this->items = $cotizaciones;

        $total_items = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $table_name WHERE distribuidor_id = %d", $current_user_id));
        $this->set_pagination_args(array(
            'total_items' => $total_items,
            'per_page'    => $per_page,
            'total_pages' => ceil($total_items / $per_page)
        ));
    }
}


// Paso 3: Crear la función que muestra la tabla de cotizaciones enviadas

function mostrar_cotizaciones_enviadas() {
    $cotizacionesEnviadasList = new Cotizaciones_Enviadas_List();

    // Si el formulario es enviado, actualizar el estado de la cotización
    if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['actualizar_estado']) && isset($_POST['estado_cotizacion'])) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'cotizaciones';
        $id = intval($_POST['actualizar_estado']);
        $nuevo_estado = sanitize_text_field($_POST['estado_cotizacion'][$id]);
        $wpdb->update(
            $table_name,
            array('estado' => $nuevo_estado),
            array('id' => $id, 'vendedor_id' => get_current_user_id()) // Asegurarse de que solo el vendedor pueda editar sus propias cotizaciones
        );
    }

    $cotizacionesEnviadasList->prepare_items();

    echo '<div class="wrap">';
    echo '<h2>Cotizaciones Enviadas</h2>';

    echo '<form method="get">';
    echo '<input type="hidden" name="page" value="' . esc_attr($_REQUEST['page']) . '">';
    echo '<p class="search-box">';
    echo '<label class="screen-reader-text" for="search-input">Buscar:</label>';
    echo '<input type="search" id="search-input" name="s" value="' . (isset($_REQUEST['s']) ? esc_attr($_REQUEST['s']) : '') . '">';
    echo '<input type="submit" id="search-submit" class="button" value="Buscar">';
    echo '</p>';
    echo '</form>';
    
    // Usamos POST aquí porque estamos actualizando la base de datos
    echo '<form method="post">';
    $cotizacionesEnviadasList->display();
    echo '</form>';

    echo '</div>';
}

function custom_content_modifications($content) {
    // Verifica si estamos en la página "carrito de cotizaciones"
    if (is_page('carrito-de-cotizaciones')) {
        return mostrar_carrito_cotizacion($content);
    }

    // Verifica si estamos en la página "solicitar cotización"
    if (is_page('solicitar-cotizacion')) {
        return mostrar_formulario_cotizacion($content);
    }

    // Si no estamos en las páginas específicas, devuelve el contenido original sin modificaciones
    return $content;
}

// Modifica el hook the_content para usar la nueva función
remove_action('the_content', 'mostrar_carrito_cotizacion');  // Elimina el hook directo anterior
remove_action('the_content', 'mostrar_formulario_cotizacion');  // Elimina el hook directo anterior
add_action('the_content', 'custom_content_modifications');

function shortcode_mostrar_carrito() {
    ob_start();  // Comienza a capturar el output
    mostrar_carrito_cotizacion();
    return ob_get_clean();  // Devuelve el output capturado y limpia el buffer
}
add_shortcode('mostrar_carrito', 'shortcode_mostrar_carrito');

function shortcode_mostrar_formulario_cotizacion() {
    ob_start();  // Comienza a capturar el output
    mostrar_formulario_cotizacion();
    return ob_get_clean();  // Devuelve el output capturado y limpia el buffer
}
add_shortcode('mostrar_formulario', 'shortcode_mostrar_formulario_cotizacion');

function custom_add_to_cart_text($text) {
    if (es_cliente()) {
        return __('Agregar a Cotización', 'woocommerce');
    }
    return $text; // Retorna el texto original si no es un cliente
}
add_filter('woocommerce_product_single_add_to_cart_text', 'custom_add_to_cart_text');
add_filter('woocommerce_product_add_to_cart_text', 'custom_add_to_cart_text');

function custom_add_to_cart_url($url, $product) {
    if (es_cliente()) {
        $distribuidor_asignado = get_the_author_meta('distribuidor_asignado', get_current_user_id());
        if ($distribuidor_asignado) {
            return get_site_url() . '/agregar-cotizacion?product_id=' . $product->get_id();
        }
    }
    return $url; // Retorna la URL original si no es un cliente o no tiene distribuidor asignado
}
add_filter('woocommerce_product_single_add_to_cart_url', 'custom_add_to_cart_url', 10, 2);
add_filter('woocommerce_product_add_to_cart_url', 'custom_add_to_cart_url', 10, 2);

function custom_woocommerce_scripts() {
    if (es_cliente()) {
        ?>
        <script type="text/javascript">
            jQuery(document).ready(function($) {
                // Desenlazar el evento click de WooCommerce del botón "añadir al carrito"
                $(document.body).off('click', '.add_to_cart_button');

                // Cambiar el comportamiento del botón para que actúe como un enlace regular
                $('.add_to_cart_button').on('click', function(e) {
                    e.preventDefault();
                    window.location.href = $(this).attr('href');
                });

                // Cambiar el texto del botón de Elementor Pro
                $('.elementor-button-text').text('Agregar a Cotización'); 
            });
        </script>
        <?php
    }
}
add_action('wp_footer', 'custom_woocommerce_scripts');


function agregar_capacidades_cliente() {
    $role = get_role('customer'); // Obtener el rol "cliente"
    
    if (!$role) return; // Si el rol no existe, salir de la función
    
    $role->add_cap('read'); // Permite al cliente ver el backend
    $role->add_cap('edit_posts'); // Permite ver la sección de entradas
    $role->add_cap('view_sent_quotes'); // Capacidad personalizada para ver cotizaciones enviadas
    $role->add_cap('edit_quotes'); // Añadir capacidad para editar

}
add_action('init', 'agregar_capacidades_cliente');

function restringir_capacidades_cliente() {
    $role = get_role('customer');
    
    if (!$role) return;
    
    $role->remove_cap('publish_posts'); // Elimina la capacidad de publicar nuevas entradas
    $role->remove_cap('edit_published_posts'); // Elimina la capacidad de editar entradas publicadas
}
add_action('init', 'restringir_capacidades_cliente');

function cotizaciones_admin_styles() {
    echo '
    <style>
/* Contenedor principal de las cotizaciones */

.cotizaciones-container {
    margin-top: 20px;
    display: flex;
    flex-wrap: wrap;
    gap: 16px;
    justify-content: flex-start;
}

/* Estilo para cada tarjeta de cotización */
.cotizacion-card {
    flex: 1 1 calc(33.333% - 16px); /* Esto debería hacer que las tarjetas ocupen 1/3 del ancho del contenedor, descontando el espacio del gap */
    margin: 0; /* Aseguramos que no haya margen adicional */
    box-sizing: border-box;
    float: none;
    position: static;
    background-color: #f9f9f9;
    border-radius: 8px;
    box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
    padding: 16px;
    width: calc(33.333% - 10.67px); /* para 3 tarjetas por fila, ajusta según necesidad */
    transition: all 0.3s;
}

.cotizacion-card:hover {
    box-shadow: 0 6px 8px rgba(0, 0, 0, 0.1);
    transform: translateY(-5px);
}

/* Estilos para los títulos y valores dentro de la tarjeta */
.cotizacion-card strong {
    display: block;
    margin-bottom: 8px;
    color: #333;
    font-size: 14px;
}

.cotizacion-card span,
.cotizacion-card select {
    font-size: 12px;
    margin-bottom: 12px;
    display: block;
}

/* Estilo para el botón de acción */
.cotizacion-actions button {
    background-color: #007BFF;
    color: #fff;
    border: none;
    padding: 8px 12px;
    border-radius: 4px;
    cursor: pointer;
    transition: all 0.3s;
}

.cotizacion-actions button:hover {
    background-color: #0056b3;
}

/* Resetea estilos para el contenedor de la barra de búsqueda */
.search-container {
    float: none; /* Elimina cualquier flotación */
    position: static; /* Asegura que no tenga posicionamiento absoluto */
    width: 100%;
    margin-bottom: 20px; /* Espacio entre la barra de búsqueda y las tarjetas */
}

/* Resetea estilos para la barra de búsqueda */
.search-form {
    float: none; /* Elimina cualquier flotación */
    position: static; /* Asegura que no tenga posicionamiento absoluto */
    width: 100%;
}

.search-form {
    margin-bottom: 25px; /* Espacio entre la barra de búsqueda y las tarjetas */
    margin-top: 25px;
    position: relative; /* Para posicionar el botón de búsqueda dentro del formulario */
}

.productos-list {
    display: flex;
    flex-direction: column;
    gap: 10px; /* Espacio entre productos */
}

.producto-item {
    display: flex;
    align-items: center;
}

.producto-img img {
    width: 40px;
    height: 40px;
    object-fit: cover;
    border-radius: 5px;
    margin-right: 10px;
}

.producto-info {
    display: flex;
    gap: 5px;
    align-items: center;
}

.producto-info > span {
    font-weight: bold;
}


/* Estilos para dispositivos móviles */
@media (max-width: 768px) {
    .cotizacion-card {
        width: 100%;
        margin-bottom: 20px;
    }
}

    </style>
    ';
}
add_action('admin_head', 'cotizaciones_admin_styles');


?>