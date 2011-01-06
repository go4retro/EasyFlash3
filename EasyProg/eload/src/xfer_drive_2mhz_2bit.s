

; =============================================================================
;
; Send a byte to the host.
;
; parameters:
;       Byte in A
;
; return:
;       -
;
; changes:
;       A, Y, zptmp
;
; =============================================================================
        ; serport: | A_in | DEV | DEV | ACK_out || C_out | C_in | D_out | D_in |
drv_send_2mhz:
        bit serport             ; check for ATN
        bmi drv_exit_2          ; leave the drive code if it is active

        ; Handshake Step 1: Drive signals byte ready with DATA low
        ldy #$02
        sty serport

        ; I moved this after Step 1 because the C64
        ; makes SEI and the badline test now
        tay
        lsr
        lsr                     ; prepare high nibble
        lsr                     ; 4th shift after pla
        pha

        ; Handshake Step 2: Host sets CLK low to acknowledge
        lda #$04
@wait2:
        bit serport             ; wait for CLK low (that's 1!)
        beq @wait2

        ; Handshake Step 3: Host releases CLK - Timing base
@wait3:
        bit serport             ; wait for CLK high (that's 0!)
        bne @wait3              ; t = 3..9 * 0.5 us = 1.5..4.5 us

        ; 2 MHz code
        ; get CLK, DATA pairs for low nibble
        tya                     ;  5..
        and #$0f                ;  7..
        tay                     ;  9..
        lda drv_sendtbl,y       ; 13..
        pha                     ; 16..
        pla                     ; 20..

        sta serport             ; 24..30 - b0 b1 (CLK DATA)

        asl                     ; 26..
        and #$0f                ; 28..
        nop
        nop
        nop
        nop                     ; 36..
        sta serport             ; 40..46 - b2 b3

        pla                     ; 44..
        lsr                     ; 46.. 4th shift
        tay                     ; 48..
        lda drv_sendtbl,y       ; 52..  get CLK, DATA pairs for high nibble
        sta serport             ; 56..62 - b4 b5

        asl                     ; 58..
        and #$0f                ; 60..
        nop
        nop
        nop
        nop                     ; 68..
        sta serport             ; 72..78 - b6 b7

        jsr delay18             ; 90..
        lda #$00                ; 92..
        sta serport             ; 96..102  set CLK and DATA high
        rts

; =============================================================================
;
; =============================================================================
delay18:
        nop
delay16:
        nop
delay14:
        nop
delay12:
        rts

drv_exit_2:
        jmp drv_exit

; =============================================================================
;
; =============================================================================
drv_recv_2mhz:
        lda #$08                ; CLK low to signal that we're receiving
        sta serport

        lda serport             ; get EOR mask for data
        asl
        eor serport
        and #$e0
        sta @eor

        lda #$01
:
        bit serport             ; wait for DATA low
        bmi drv_exit_2
        beq :-

        sei

        lda #0                  ; release CLK
        sta serport

        lda #$01
:
        bit serport             ; wait for DATA high
        bne :-                  ; t = 3..9

; 2 MHz code
        jsr delay16             ; 19..

        lda serport             ; 23..29 (11.5..14.5 us)    get bits 7 and 5
        asl                     ; 25..

                                ; 39..
        jsr eor_serport_24cyc   ; 43..49 (21.5..24.5 us)    get bits 6 and 4
                                ; 49..

        asl
        asl
        asl                     ; 55..

        nop                     ; 57..

                                ; 71..
        jsr eor_serport_24cyc   ; 75..81 (37.5..40.5 us)    get bits 3 and 1
                                ; 81..

        asl                     ; 83..

@eor = * + 1
        eor #$5e                ; 85..

                                ; 93..
        jmp eor_serport_18cyc   ; 97..103 (48.5..51.5 us)   get bits 2 and 0

eor_serport_24cyc:              ;   /6 (jsr)
        nop
        nop
        nop
eor_serport_18cyc:              ;  6/  (jsr)
        nop                     ;  8/14
        eor serport             ; 12/18
        rts                     ; 18/24