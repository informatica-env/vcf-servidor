

add_action('rest_api_init', function () {
    // ── GET Form Schema ──────────────────────────────────────────────
    register_rest_route('headless/v1', '/form/(?P<id>\d+)', [
        'methods'             => 'GET',
        'permission_callback' => '__return_true',
        'callback'            => function ($req) {
            $form_id   = (int) $req['id'];
            $form      = wpforms()->form->get($form_id, ['cap' => false]);
            if (!$form) {
                return new WP_Error('not_found', 'Form not found', ['status' => 404]);
            }
            $form_data = wpforms_decode($form->post_content);
            $fields    = [];
            foreach ($form_data['fields'] as $field) {
                $info = [
                    'id'       => (int) $field['id'],
                    'label'    => $field['label'] ?? '',
                    'type'     => $field['type'],
                    'required' => !empty($field['required']),
                ];
                if (!empty($field['choices']) && is_array($field['choices'])) {
                    $info['choices'] = array_values(array_map(function ($c) {
                        $val = (isset($c['value']) && $c['value'] !== '') ? $c['value'] : ($c['label'] ?? '');
                        return ['label' => $c['label'] ?? '', 'value' => $val];
                    }, $field['choices']));
                }
                $fields[] = $info;
            }
            return rest_ensure_response([
                'form_id' => (string) $form_id,
                'title'   => $form_data['settings']['form_title'] ?? '',
                'fields'  => $fields,
            ]);
        },
    ]);

    // ── POST Form Submit ─────────────────────────────────────────────
    register_rest_route('headless/v1', '/form-submit/(?P<form_id>\d+)', [
        'methods'             => 'POST',
        'permission_callback' => '__return_true',
        'callback'            => function ($req) {
            $form_id = (int) $req['form_id'];
            $params  = $req->get_json_params();

            if (!$form_id || empty($params['fields'])) {
                return new WP_REST_Response(['success' => false, 'message' => 'Datos incompletos'], 400);
            }

            if (!function_exists('wpforms')) {
                return new WP_REST_Response(['success' => false, 'message' => 'WPForms no está activo'], 500);
            }

            $form = wpforms()->form->get($form_id);
            if (!$form) {
                return new WP_REST_Response(['success' => false, 'message' => 'Formulario no encontrado'], 404);
            }

            $form_data = wpforms_decode($form->post_content);
            if (!$form_data) {
                return new WP_REST_Response(['success' => false, 'message' => 'No se pudo cargar el formulario'], 500);
            }

            // Build field type map
            $field_map = [];
            foreach ($form_data['fields'] as $f) {
                $field_map[(int) $f['id']] = $f;
            }

            // Normalize and validate fields
            $errors = [];
            $fields = [];
            foreach ($params['fields'] as $id => $val) {
                $fid  = (int) $id;
                $type = isset($field_map[$fid]) ? $field_map[$fid]['type'] : '';
                $req  = isset($field_map[$fid]) && !empty($field_map[$fid]['required']);

                // Required check
                if ($req && empty($val) && $val !== '0') {
                    $errors[$id] = 'Este campo es obligatorio.';
                    continue;
                }

                if ($type === 'checkbox') {
                    if (!empty($val) && !empty($field_map[$fid]['choices'])) {
                        $first = reset($field_map[$fid]['choices']);
                        $choice_val = (isset($first['value']) && $first['value'] !== '') ? $first['value'] : ($first['label'] ?? '');
                        $fields[$id] = [$choice_val];
                    } else {
                        $fields[$id] = [];
                    }
                } elseif ($type === 'select' && !empty($field_map[$fid]['choices']) && !empty($val)) {
                    $valid = false;
                    foreach ($field_map[$fid]['choices'] as $c) {
                        $choice_val = (isset($c['value']) && $c['value'] !== '') ? $c['value'] : ($c['label'] ?? '');
                        if ($val === $choice_val) {
                            $valid = true; break;
                        }
                    }
                    if (!$valid) {
                        $errors[$id] = 'The selected option is invalid.';
                    } else {
                        $fields[$id] = $val;
                    }
                } else {
                    $fields[$id] = $val;
                }
            }

            if (!empty($errors)) {
                return new WP_REST_Response([
                    'success' => false,
                    'errors'  => ['field' => $errors],
                    'message' => 'Errores de validación',
                ], 400);
            }

            // Build entry for WPForms notifications
            $entry = [
                'id'     => $form_id,
                'fields' => $fields,
            ];

            // Try to use WPForms notify() if public; fallback to wp_mail
            try {
                $reflect = new ReflectionMethod(wpforms()->process, 'notify');
                if ($reflect->isPublic()) {
                    wpforms()->process->notify($entry, $form_data);
                } else {
                    headless_send_mail($entry, $form_data);
                }
            } catch (Exception $e) {
                headless_send_mail($entry, $form_data);
            }

            return new WP_REST_Response([
                'success' => true,
                'message' => $form_data['settings']['confirmation_message'] ?? 'Formulario enviado correctamente',
            ], 200);
        },
    ]);

    // ── GET Nonce ────────────────────────────────────────────────
    register_rest_route('headless/v1', '/form-nonce/(?P<id>\d+)', [
        'methods'             => 'GET',
        'permission_callback' => '__return_true',
        'callback'            => function ($req) {
            $form_id = (int) $req['id'];
            return rest_ensure_response([
                'nonce'   => wp_create_nonce("wpforms_{$form_id}"),
                'token'   => '',
                'form_id' => $form_id,
            ]);
        },
    ]);
});

/** Fallback: send email directly if WPForms notify() is not accessible */
function headless_send_mail($entry, $form_data) {
    $settings = $form_data['settings'];
    $subject = !empty($settings['notification']['subject'])
        ? str_replace('{field_id="0"}', $entry['fields'][0] ?? '', $settings['notification']['subject'])
        : 'Formulario: ' . ($settings['form_title'] ?? 'Sin título');
    $to      = !empty($settings['notification']['email']) ? $settings['notification']['email'] : get_option('admin_email');
    $message = '';
    foreach ($form_data['fields'] as $f) {
        $fid = (int) $f['id'];
        if (isset($entry['fields'][$fid])) {
            $val = $entry['fields'][$fid];
            if (is_array($val)) $val = implode(', ', $val);
            $message .= ($f['label'] ?? "Campo $fid") . ": " . $val . "\n";
        }
    }
    $headers = ['Content-Type: text/plain; charset=UTF-8'];
    wp_mail($to, $subject, $message, $headers);
}