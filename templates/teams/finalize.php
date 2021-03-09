<?php
script('openidconnect', 'teams-sdk', true);
style('openidconnect', 'teams');
?>

<script type="application/javascript">
    microsoftTeams.initialize();

    function notifySuccess() {
        microsoftTeams.authentication.notifySuccess({url: '<?php print_unescaped($_['url']) ?>'});
    }
</script>

<script type="application/javascript">
    notifySuccess();
</script>