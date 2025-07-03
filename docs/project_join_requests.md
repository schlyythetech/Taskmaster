# Project Join Request System

This document describes the project join request system implemented in TaskMaster.

## Overview

The project join request system allows users to request to join projects created by other users. Project owners can then approve or deny these requests through the notification system or directly on the project page.

## User Flow

1. **Request to Join**
   - User visits the Projects page
   - User clicks "Request to Join" on a project
   - A notification is sent to the project owner
   - User is added to the project with a "pending" status

2. **Owner Approval/Denial**
   - Project owner receives a notification with "Accept" and "Deny" buttons
   - Owner can also see pending requests on the project page
   - When owner accepts: 
     - User's status is changed to "active"
     - User receives a notification
     - User can now access the project
   - When owner denies:
     - User's status is changed to "rejected"
     - User receives a notification
     - User can request to join again later

## Technical Implementation

### Database Changes

- Added `status` column to `project_members` table with values:
  - `pending`: User has requested to join but has not been approved
  - `active`: User is an active member of the project
  - `rejected`: User's request to join was denied

- Added notification types to `notifications` table:
  - `join_request`: Sent to project owner when a user requests to join
  - `join_approved`: Sent to user when their request is approved
  - `join_rejected`: Sent to user when their request is denied
  
- Added `related_user_id` column to the `notifications` table to track user associations

### Key Files

- `join_project.php`: Handles join requests
- `notification_api.php`: Processes accept/deny actions from notifications
- `approve_member.php`: Handles approval from project page
- `reject_member.php`: Handles rejection from project page
- `includes/notification_overlay.php`: Displays notifications with action buttons

### Action Handling

When a notification is created for a join request, it includes:
- `related_id`: The project ID
- `related_user_id`: The user ID requesting to join
- `type`: Set to 'join_request'
- `message`: A message indicating the user's request

The notification system then displays Accept and Deny buttons when the notification type is 'join_request'.
When clicked:
1. The action is sent to notification_api.php
2. The membership status is updated in the database
3. A notification is sent to the requesting user with the result
4. The UI is updated to reflect the action

## Testing

For testing purposes, you can use:
- `test_project_requests.php`: Creates a test join request notification
- `fix_project_join_requests.php`: Checks and fixes database structure

## Future Improvements

- Add ability for project owners to add users directly (without request)
- Implement expiration for pending requests
- Add email notifications for join requests
- Allow project admins (not just owners) to approve/deny requests 