-- Add trip column to roll_received table
-- Run this SQL in phpMyAdmin or MySQL command line

ALTER TABLE roll_received ADD COLUMN trip INT DEFAULT NULL;

