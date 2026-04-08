<?php
function tlt_current_url() {
    return home_url( add_query_arg( [], $_SERVER['REQUEST_URI'] ?? '' ) );
}

add_shortcode( 'tapojarvi_login_tabs', function () {

    $url = tlt_current_url();
    $err = '';

    // Näytä virheilmoitukset käännettyinä
    if ( isset($_GET['login']) && $_GET['login'] === 'empty' ) {
        $err = '<div class="login-error">' .
            esc_html__( 'Please enter the email address you registered with, or request a login link from the Employee tab.', 'tapojarvi-login-tabs' ) .
        '</div>';
    } elseif ( isset($_GET['login']) && $_GET['login'] === 'failed' ) {
        $err = '<div class="login-error">' .
            esc_html__( 'Incorrect username or password.', 'tapojarvi-login-tabs' ) .
        '</div>';
    }

    ob_start(); ?>
    <div class="tapo-tabs">
        <ul class="tapo-nav">
            <li>
                <a href="#" data-target="tapo-mgr" class="active">
                    <?php echo esc_html__( 'Order Management', 'tapojarvi-login-tabs' ); ?>
                </a>
            </li>
            <li>
                <a href="#" data-target="tapo-emp">
                    <?php echo esc_html__( 'Employee', 'tapojarvi-login-tabs' ); ?>
                </a>
            </li>
            <li>
                <a href="#" data-target="tapo-size">
                    <?php echo esc_html__( 'Size calculator', 'tapojarvi-login-tabs' ); ?>
                </a>
            </li>
        </ul>

        <!-- Päällikkö / kirjautuminen -->
        <div id="tapo-mgr" class="tapo-pane active">
            <?php echo $err; ?>
            <?php
                // Tyhjä kenttä -> älä ohjaa
                if ( isset($_POST['log']) && empty($_POST['log']) ) {
                    echo '<script>window.history.replaceState(null, null, "'.esc_url_raw( $url ).'");</script>';
                }

                // Tarvittaessa voit lokalisoida wp_login_form()-labelit:
                // wp_login_form([
                //     'label_username' => esc_html__('Email address', 'tapojarvi-login-tabs'),
                //     'label_password' => esc_html__('Password', 'tapojarvi-login-tabs'),
                //     'label_remember' => esc_html__('Remember me', 'tapojarvi-login-tabs'),
                //     'label_log_in'   => esc_html__('Log in', 'tapojarvi-login-tabs'),
                // ]);
                wp_login_form();
            ?>
        </div>

        <!-- Työntekijä / Magic Link -->
        <div id="tapo-emp" class="tapo-pane">
            <?php
            echo do_shortcode(
                sprintf(
                    '[magic_login_form login-button-text="%s" logout-link-text="%s" redirect_to="%s"]',
                    esc_attr__( 'SEND LINK', 'tapojarvi-login-tabs' ),
                    esc_attr__( 'Log out', 'tapojarvi-login-tabs' ),
                    esc_url( $url )
                )
            );
            ?>
        </div>

        <!-- Mittalaskuri -->
        <div id="tapo-size" class="tapo-pane">
            <?php echo do_shortcode('[mittalaskuri]'); ?>
        </div>
    </div>

    <script>
    document.addEventListener('DOMContentLoaded', function() {
        const form = document.querySelector('form');
        if (!form) return;
        const emailField = form.querySelector('input[name="log"]');
        if (!emailField) return;

        form.addEventListener('submit', function(e) {
            if (emailField.value.trim() === '') {
                e.preventDefault();
                alert('<?php echo esc_js( __( 'Enter your email address', 'tapojarvi-login-tabs' ) ); ?>');
                emailField.focus();
            }
        });
    });
    </script>
    <?php
    return ob_get_clean();
});