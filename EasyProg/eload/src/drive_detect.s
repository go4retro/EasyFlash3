

.include "kernal.i"
.include "drivetype.i"


; =============================================================================
.rodata

; This table is used to detect the type of a disk drive.
; The first entry which matches is used. Table must be smaller than 256 bytes!
;
; It has following format for each entry:
; - 4 bytes     Substring to be found, may end with '*'s as wildcard
; - 1 byte      Drive type ID (refer to drive_type.s)
drive_id_tab:
        .byte "SD2I", drivetype_sd2iec
        .byte "UIEC", drivetype_sd2iec
        .byte "1541", drivetype_1541
        .byte "1570", drivetype_1570
        .byte "1571", drivetype_1571
        .byte "1581", drivetype_1581
        ;.byte "FD**", drivetype_fd
        ;.byte "HD**", drivetype_hd
        .byte "VICE", drivetype_vice
        .byte "PARA", drivetype_1541 ; groepaz has a speeddos clone called parados
        .byte 0 ; end marker

str_ui:
        .byte "UI"
str_ui_len = * - str_ui

.bss

drive_id_str_size = 32
drive_id_str:
        .res drive_id_str_size

.code
; =============================================================================

; =============================================================================
;
; =============================================================================
.export drive_detect
drive_detect:
        ; clear id string
        lda #0
        ldy #drive_id_str_size - 1
@clear:
        sta drive_id_str, y
        dey
        bpl @clear

        ; ask the drive to send its ID
        lda #str_ui_len     ; set name to M-R command string
        ldx #<str_ui
        ldy #>str_ui
        jsr send_command
        bcs @fail
        jsr send_talk
        ldy #0
@next_byte:
        lda ST
        bne @end_of_id
        jsr ACPTR
        sta drive_id_str, y
        sta $0400, y
        iny
        cpy #drive_id_str_size
        bne @next_byte
@end_of_id:
        jsr close_command

        ; search for the substrings in this string
        ldx #0              ; points into drive_id_tab
@check_next_entry:
        ldy #0              ; points into drive_id_str
@check_next_pos:
        lda drive_id_str, y
        cmp drive_id_tab, x
        bne @no_match
        lda drive_id_str + 1, y
        cmp drive_id_tab + 1, x
        bne @no_match
        lda drive_id_str + 2, y
        cmp drive_id_tab + 2, x
        bne @no_match
        lda drive_id_str + 3, y
        cmp drive_id_tab + 3, x
        beq @match
@no_match:
        iny
        cpy #drive_id_str_size - 4
        bne @check_next_pos
        inx
        inx
        inx
        inx
        inx
        lda drive_id_tab, x
        bne @check_next_entry
@fail:
        lda #drivetype_unknown  ; unknown or no drive present
        rts
@match:
        lda drive_id_tab + 4, x
        rts


; =============================================================================
;
; =============================================================================
send_command:
        jsr SETNAM

        ; set secondary address
        lda #$6f
        sta $b9

        ; send secondary address and command, carry set on fail
        ; clears $90 (ST) if there was no error
        jmp $f3d5


; =============================================================================
;
; =============================================================================
close_command:
        jsr UNTLK
        jmp $f642           ; close


; =============================================================================
;
; =============================================================================
send_talk:
        lda #0
        sta ST
        lda $ba
        jsr TALK
        lda #$6f
        jmp TKSA
