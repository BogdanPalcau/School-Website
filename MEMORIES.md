# Bug hunt memories

- **bootstrap.php `portal_set_user_account_status`:** non-owner admin could ban/mute/restrict peer admins because status updates lacked the owner-only check used by delete/`can_act`.
  - PR: https://github.com/BogdanPalcau/School-Website/pull/9
  - Status: open
  - Recorded: 2026-08-13
